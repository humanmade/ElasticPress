<?php
/**
 * Comment indexable
 *
 * @since   3.1
 * @package elasticpress
 */

namespace ElasticPress\Indexable\Comment;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;
use ElasticPress\Indexable\Post\DateQuery as DateQuery;
use \WP_Comment_Query as WP_Comment_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Comment indexable class
 */
class Comment extends Indexable {

	/**
	 * Indexable slug
	 *
	 * @var   string
	 * @since 3.1
	 */
	public $slug = 'comment';

	/**
	 * Create indexable and initialize dependencies
	 *
	 * @since 3.1
	 */
	public function __construct() {
		$this->labels = [
			'plural'   => esc_html__( 'Comments', 'elasticpress' ),
			'singular' => esc_html__( 'Comment', 'elasticpress' ),
		];

		$this->sync_manager      = new SyncManager( $this->slug );
		$this->query_integration = new QueryIntegration();
	}

	/**
	 * Format query vars into ES query
	 *
	 * @param  array $query_vars WP_Comment_Query args.
	 * @since  3.1
	 * @return array
	 */
	public function format_args( $query_vars ) {
		/**
		 * Support `number` query var
		 */
		if ( ! empty( $query_vars['number'] ) ) {
			$number = (int) $query_vars['number'];
		} else {
			/**
			 * Set the maximum results window size.
			 *
			 * The request will return a HTTP 500 Internal Error if the size of the
			 * request is larger than the [index.max_result_window] parameter in ES.
			 * See the scroll api for a more efficient way to request large data sets.
			 *
			 * @return int The max results window size.
			 *
			 * @since 2.3.0
			 */
			$number = apply_filters( 'ep_max_results_window', 10000 );
		}

		$formatted_args = [
			'from' => 0,
			'size' => $number,
		];

		/**
		 * Support `offset` query var
		 */
		if ( isset( $query_vars['offset'] ) ) {
			$formatted_args['from'] = (int) $query_vars['offset'];
		}

		/**
		 * Support `paged` query var
		 *
		 * If `offset` is used, that takes precendence
		 * over this.
		 */
		if ( isset( $query_vars['paged'] ) && empty( $query_vars['offset'] ) && $query_vars['paged'] > 1 ) {
			$formatted_args['from'] = $number * ( $query_vars['paged'] - 1 );
		}

		/**
		 * Support `order` and `orderby` query vars
		 */

		// Set sort order, default is 'desc'.
		if ( ! empty( $query_vars['order'] ) ) {
			$order = $this->parse_order( $query_vars['order'] );
		} else {
			$order = 'desc';
		}

		// Default sort by comment date
		if ( empty( $query_vars['orderby'] ) ) {
			$query_vars['orderby'] = 'comment_date_gmt';
		}

		// Set sort type.
		$formatted_args['sort'] = $this->parse_orderby( $query_vars['orderby'], $order, $query_vars );

		$filter = [
			'bool' => [
				'must' => [],
			],
		];

		$use_filters = false;

		/**
		 * Support `author_email` query var
		 */
		if ( ! empty( $query_vars['author_email'] ) ) {
			$filter['bool']['must'][] = [
				'term' => [
					'comment_author_email.raw' => $query_vars['author_email'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `author_url` query var
		 */
		if ( ! empty( $query_vars['author_url'] ) ) {
			$filter['bool']['must'][] = [
				'term' => [
					'comment_author_url.raw' => $query_vars['author_url'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `user_id` query var
		 */
		if ( ! empty( $query_vars['user_id'] ) ) {
			$filter['bool']['must'][] = [
				'term' => [
					'user_id' => (int) $query_vars['user_id'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `author__in` query var
		 */
		if ( ! empty( $query_vars['author__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'user_id' => array_values( (array) $query_vars['author__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `author__not_in` query var
		 */
		if ( ! empty( $query_vars['author__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'user_id' => array_values( (array) $query_vars['author__not_in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `comment__in` query var
		 */
		if ( ! empty( $query_vars['comment__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'comment_ID' => array_values( (array) $query_vars['comment__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `comment__not_in` query var
		 */
		if ( ! empty( $query_vars['comment__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'comment_ID' => array_values( (array) $query_vars['comment__not_in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `date_query` query var
		 */
		if ( ! empty( $query_vars['date_query'] ) ) {
			$date_query  = new DateQuery( $query_vars['date_query'] );
			$date_filter = $date_query->get_es_filter();

			if ( array_key_exists( 'and', $date_filter ) ) {
				$filter['bool']['must'][] = $date_filter['and'];
				$use_filters              = true;
			}
		}

		/**
		 * Support `fields` query var.
		 */
		if ( isset( $query_vars['fields'] ) ) {
			switch ( $query_vars['fields'] ) {
				case 'ids':
					$formatted_args['_source'] = [
						'includes' => [
							'comment_ID',
						],
					];
					break;
			}
		}

		/**
		 * Support `karma` query var.
		 */
		if ( ! empty( $query_vars['karma'] ) || 0 === $query_vars['karma'] ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_karma' => $query_vars['karma'],
				],
			];

			$use_filters = true;
		}

		$meta_queries = [];

		/**
		 * Support `meta_key` and `meta_value` query args
		 */
		if ( ! empty( $query_vars['meta_key'] ) ) {
			$meta_query_array = [
				'key' => $query_vars['meta_key'],
			];

			if ( isset( $query_vars['meta_value'] ) ) {
				$meta_query_array['value'] = $query_vars['meta_value'];
			}

			$meta_queries[] = $meta_query_array;
		}

		/**
		 * Support 'meta_query' query var.
		 */
		if ( ! empty( $query_vars['meta_query'] ) ) {
			$meta_queries = array_merge( $meta_queries, $query_vars['meta_query'] );
		}

		if ( ! empty( $meta_queries ) ) {
			$built_meta_queries = $this->build_meta_query( $meta_queries );

			if ( $built_meta_queries ) {
				$filter['bool']['must'][] = $built_meta_queries;
				$use_filters              = true;
			}
		}

		/**
		 * Support `hierarchical` query var
		 */
		if ( ! empty( $query_vars['hierarchical'] ) && empty( $query_vars['parent'] ) ) {
			$query_vars['parent'] = 0;
		}

		/**
		 * Support `parent` query var.
		 */
		if ( ! empty( $query_vars['parent'] ) || 0 === $query_vars['parent'] ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_parent' => (int) $query_vars['parent'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `parent__in` query var
		 */
		if ( ! empty( $query_vars['parent__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'comment_parent' => array_values( (array) $query_vars['parent__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `parent__not_in` query var
		 */
		if ( ! empty( $query_vars['parent__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'comment_parent' => array_values( (array) $query_vars['parent__not_in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_author` query var.
		 */
		if ( ! empty( $query_vars['post_author'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_post_author_ID' => (int) $query_vars['post_author'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_author__in` query var
		 */
		if ( ! empty( $query_vars['post_author__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'comment_post_author_ID' => array_values( (array) $query_vars['post_author__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_author__not_in` query var
		 */
		if ( ! empty( $query_vars['post_author__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'comment_post_author_ID' => array_values( (array) $query_vars['post_author__not_in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_id` query var.
		 */
		if ( ! empty( $query_vars['post_id'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_post_ID' => (int) $query_vars['post_id'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post__in` query var
		 */
		if ( ! empty( $query_vars['post__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'comment_post_ID' => array_values( (array) $query_vars['post__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post__not_in` query var
		 */
		if ( ! empty( $query_vars['post__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'comment_post_ID' => array_values( (array) $query_vars['post__not_in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_status` query var
		 */
		if ( ! empty( $query_vars['post_status'] ) && 'any' !== $query_vars['post_status'] ) {
			$post_status    = (array) ( is_string( $query_vars['post_status'] ) ? explode( ',', $query_vars['post_status'] ) : $query_vars['post_status'] );
			$post_status    = array_map( 'trim', $post_status );
			$terms_map_name = 'terms';

			if ( count( $post_status ) < 2 ) {
				$terms_map_name = 'term';
				$post_status    = $post_status[0];
			}

			$filter['bool']['must'][] = array(
				$terms_map_name => array(
					'comment_post_status' => $post_status,
				),
			);

			$use_filters = true;
		}

		/**
		 * Support `post_type` query var.
		 */
		if ( ! empty( $query_vars['post_type'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_post_type' => $query_vars['post_type'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_name` query var.
		 */
		if ( ! empty( $query_vars['post_name'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_post_name' => $query_vars['post_name'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `post_parent` query var.
		 */
		if ( ! empty( $query_vars['post_parent'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'comment_post_parent' => (int) $query_vars['post_parent'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `search` query_var
		 */
		if ( ! empty( $query_vars['search'] ) ) {

			$search        = ! empty( $query_vars['search'] ) ? $query_vars['search'] : '';
			$search_fields = [];

			/**
			 * Allow for search field specification
			 */
			if ( ! empty( $query_vars['search_fields'] ) ) {
				$search_fields = $query_vars['search_fields'];
			}

			if ( ! empty( $search_fields ) ) {
				$prepared_search_fields = [];

				if ( ! empty( $search_fields['meta'] ) ) {
					$metas = (array) $search_fields['meta'];

					foreach ( $metas as $meta ) {
						$prepared_search_fields[] = 'meta.' . $meta . '.value';
					}

					unset( $search_fields['meta'] );
				}

				$prepared_search_fields = array_merge( $search_fields, $prepared_search_fields );
			} else {
				$prepared_search_fields = [
					'comment_author',
					'comment_author_email',
					'comment_author_url',
					'comment_author_IP',
					'comment_content',
				];
			}

			$prepared_search_fields = apply_filters( 'ep_comment_search_fields', $prepared_search_fields, $query_vars );

			$query = [
				'bool' => [
					'should' => [
						[
							'multi_match' => [
								'query'  => $search,
								'type'   => 'phrase',
								'fields' => $prepared_search_fields,
								'boost'  => apply_filters( 'ep_comment_match_phrase_boost', 4, $prepared_search_fields, $query_vars ),
							],
						],
						[
							'multi_match' => [
								'query'     => $search,
								'fields'    => $prepared_search_fields,
								'boost'     => apply_filters( 'ep_comment_match_boost', 2, $prepared_search_fields, $query_vars ),
								'fuzziness' => 0,
								'operator'  => 'and',
							],
						],
						[
							'multi_match' => [
								'fields'    => $prepared_search_fields,
								'query'     => $search,
								'fuzziness' => apply_filters( 'ep_comment_fuzziness_arg', 1, $prepared_search_fields, $query_vars ),
							],
						],
					],
				],
			];

			$formatted_args['query'] = apply_filters( 'ep_comment_formatted_args_query', $query, $query_vars );

		} else {
			$formatted_args['query']['match_all'] = [
				'boost' => 1,
			];
		}

		/**
		 * Support `status` query var
		 */
		if ( ! empty( $query_vars['status'] ) && 'all' !== $query_vars['status'] ) {
			$comment_stati = (array) ( is_string( $query_vars['status'] ) ? explode( ',', $query_vars['status'] ) : $query_vars['status'] );
			$comment_stati = array_map( 'trim', $comment_stati );

			foreach ( $comment_stati as $key => $status ) {
				if ( 'hold' === $status ) {
					$comment_stati[ $key ] = 0;
				}

				if ( 'approve' === $status ) {
					$comment_stati[ $key ] = 1;
				}
			}

			$terms_map_name = 'terms';

			if ( count( $comment_stati ) < 2 ) {
				$terms_map_name = 'term';
				$comment_stati  = $comment_stati[0];
			}

			/**
			 * Support `include_unapproved` query var
			 */
			if ( ! empty( $query_vars['include_unapproved'] ) ) {
				$include_unapproved = wp_parse_list( $query_vars['include_unapproved'] );
				$unapproved_ids     = [];
				$unapproved_emails  = [];

				foreach ( $include_unapproved as $unapproved_identifier ) {
					// Numeric values are assumed to be user ids.
					if ( is_numeric( $unapproved_identifier ) ) {
						$unapproved_ids[] = $unapproved_identifier;

						// Otherwise we assume it's an email address.
					} else {
						$unapproved_emails[] = $unapproved_identifier;
					}
				}

				$filter['bool']['must'][]['bool']['should'] = [
					[
						$terms_map_name => [
							'comment_approved' => $comment_stati,
						],
					],
					[
						'terms' => [
							'user_id' => array_values( array_map( 'absint', $unapproved_ids ) ),
						],
					],
					[
						'terms' => [
							'comment_author_email.raw' => array_values( $unapproved_emails ),
						],
					],
				];
			} else {
				$filter['bool']['must'][] = [
					$terms_map_name => [
						'comment_approved' => $comment_stati,
					],
				];
			}

			$use_filters = true;
		}

		/**
		 * Support `type` query var
		 */
		if ( ! empty( $query_vars['type'] ) ) {
			$types = (array) ( is_string( $query_vars['type'] ) ? explode( ',', $query_vars['type'] ) : $query_vars['type'] );
			$types = array_map( 'trim', $types );

			$terms_map_name = 'terms';

			if ( count( $types ) < 2 ) {
				$terms_map_name = 'term';
				$types          = $types[0];
			}

			$filter['bool']['must'][] = [
				$terms_map_name => [
					'comment_type.raw' => $types,
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `type__in` query var
		 */
		if ( ! empty( $query_vars['type__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'comment_type.raw' => array_values( (array) $query_vars['type__in'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `type__not_in` query var
		 */
		if ( ! empty( $query_vars['type__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'comment_type.raw' => array_values( (array) $query_vars['type__not_in'] ),
				],
			];

			$use_filters = true;
		}

		if ( $use_filters ) {
			$formatted_args['post_filter'] = $filter;
		}

		return apply_filters( 'ep_comment_formatted_args', $formatted_args, $query_vars );
	}

	/**
	 * Put mapping for comments
	 *
	 * @since  3.1
	 * @return boolean
	 */
	public function put_mapping() {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		if ( empty( $es_version ) ) {
			$es_version = apply_filters( 'ep_fallback_elasticsearch_version', '2.0' );
		}

		$mapping_file = 'initial.php';

		if ( version_compare( $es_version, '5.0', '<' ) ) {
			$mapping_file = 'pre-5-0.php';
		} elseif ( version_compare( $es_version, '7.0', '>=' ) ) {
			$mapping_file = '7-0.php';
		}

		$mapping = require apply_filters( 'ep_comment_mapping_file', __DIR__ . '/../../../mappings/comment/' . $mapping_file );

		$mapping = apply_filters( 'ep_comment_mapping', $mapping );

		return Elasticsearch::factory()->put_mapping( $this->get_index_name(), $mapping );
	}

	/**
	 * Query DB for comments
	 *
	 * @param  array $args Query arguments
	 * @since  3.1
	 * @return array
	 */
	public function query_db( $args ) {
		/* START FORK CUSTOM CODE */
		$all_query_args = apply_filters( 'ep_search_all_comments_args', [
			'count' => true,
		] );

		$all_query = new WP_Comment_Query( $all_query_args );
		$defaults = apply_filters( 'ep_search_paginated_comments_args', [
			'number'  => $this->get_bulk_items_per_page(),
			'offset'  => 0,
			'orderby' => 'comment_ID',
			'order'   => 'desc',
		] );
		/* END FORK CUSTOM CODE */

		if ( isset( $args['per_page'] ) ) {
			$args['number'] = $args['per_page'];
		}

		$args = apply_filters( 'ep_comment_query_db_args', wp_parse_args( $args, $defaults ) );

		$query = new WP_Comment_Query( $args );

		array_walk( $query->comments, [ $this, 'remap_comments' ] );

		return [
			'objects'       => $query->comments,
			'total_objects' => absint( $all_query->get_comments() ),
		];
	}

	/**
	 * Prepare a comment document for indexing
	 *
	 * @param  int $comment_id Comment ID
	 * @since  3.1
	 * @return bool|array
	 */
	public function prepare_document( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment || ! is_a( $comment, 'WP_Comment' ) ) {
			return false;
		}

		$comment_post = get_post( $comment->comment_post_ID );

		$comment_args = [
			'comment_ID'             => $comment->comment_ID,
			'ID'                     => $comment->comment_ID,
			'comment_post_ID'        => $comment->comment_post_ID,
			'comment_post_author_ID' => $comment_post->post_author,
			'comment_post_status'    => $comment_post->post_status,
			'comment_post_type'      => $comment_post->post_type,
			'comment_post_name'      => $comment_post->post_name,
			'comment_post_parent'    => $comment_post->post_parent,
			'comment_author'         => $comment->comment_author,
			'comment_author_email'   => $comment->comment_author_email,
			'comment_author_url'     => $comment->comment_author_url,
			'comment_author_IP'      => $comment->comment_author_IP,
			'comment_date'           => $comment->comment_date,
			'comment_date_gmt'       => $comment->comment_date_gmt,
			'comment_content'        => $comment->comment_content,
			'comment_karma'          => $comment->comment_karma,
			'comment_approved'       => $comment->comment_approved,
			'comment_agent'          => $comment->comment_agent,
			'comment_type'           => $comment->comment_type ? $comment->comment_type : 'comment',
			'comment_parent'         => $comment->comment_parent,
			'user_id'                => $comment->user_id,
			'meta'                   => $this->prepare_meta_types( $this->prepare_meta( $comment->comment_ID ) ),
		];

		$comment_args = apply_filters( 'ep_comment_sync_args', $comment_args, $comment_id );

		return $comment_args;
	}

	/**
	 * Rebuild our comment objects to match the fields we need.
	 *
	 * In particular, result of WP_Comment_Query does not
	 * include an "id" field, which our index command
	 * expects.
	 *
	 * @param  object $value Comment object
	 * @since  3.1
	 * @return void Returns by reference
	 */
	public function remap_comments( &$value ) {
		$value = (object) [
			'ID'                   => $value->comment_ID,
			'comment_ID'           => $value->comment_ID,
			'comment_post_ID'      => $value->comment_post_ID,
			'comment_author'       => $value->comment_author,
			'comment_author_email' => $value->comment_author_email,
			'comment_author_url'   => $value->comment_author_url,
			'comment_author_IP'    => $value->comment_author_IP,
			'comment_date'         => $value->comment_date,
			'comment_date_gmt'     => $value->comment_date_gmt,
			'comment_content'      => $value->comment_content,
			'comment_karma'        => $value->comment_karma,
			'comment_approved'     => $value->comment_approved,
			'comment_agent'        => $value->comment_agent,
			'comment_type'         => $value->comment_type,
			'comment_parent'       => $value->comment_parent,
			'user_id'              => $value->user_id,
		];
	}

	/**
	 * Prepare meta to send to ES
	 *
	 * @param  int $comment_id Comment ID
	 * @since  3.1
	 * @return array
	 */
	public function prepare_meta( $comment_id ) {
		$meta = (array) get_comment_meta( $comment_id );

		if ( empty( $meta ) ) {
			return [];
		}

		$prepared_meta = [];

		/**
		 * Filter index-able private meta
		 *
		 * Allows for specifying private meta keys that may be indexed in the same manner as public meta keys.
		 *
		 * @since 3.1
		 *
		 * @param array           Array of index-able private meta keys.
		 * @param int $comment_id Comment ID.
		 */
		$allowed_protected_keys = apply_filters(
			'ep_prepare_comment_meta_allowed_protected_keys',
			[],
			$comment_id
		);

		/**
		 * Filter non-indexed public meta
		 *
		 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
		 *
		 * @since 3.1
		 *
		 * @param array           Array of public meta keys to exclude from index.
		 * @param int $comment_id Comment ID.
		 */
		$excluded_public_keys = apply_filters(
			'ep_prepare_comment_meta_excluded_public_keys',
			[],
			$comment_id
		);

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys, true ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys, true ) ) {
					$allow_index = true;
				}
			}

			if ( true === $allow_index || apply_filters( 'ep_prepare_comment_meta_whitelist_key', false, $key, $comment_id ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;
	}

	/**
	 * Parse an 'order' query variable and cast it to asc or desc as necessary.
	 *
	 * @access protected
	 *
	 * @param  string $order The 'order' query variable.
	 * @since  3.1
	 * @return string The sanitized 'order' query variable.
	 */
	protected function parse_order( $order ) {
		if ( ! is_string( $order ) || empty( $order ) ) {
			return 'desc';
		}

		if ( 'ASC' === strtoupper( $order ) ) {
			return 'asc';
		} else {
			return 'desc';
		}
	}

	/**
	 * Convert the alias to a properly-prefixed sort value.
	 *
	 * @access protected
	 *
	 * @param  string $orderby Alias or path for the field to order by.
	 * @param  string $order Order direction
	 * @param  array  $args Query args
	 * @since  3.1
	 * @return array
	 */
	protected function parse_orderby( $orderby, $order, $args ) {
		$sort = [];

		if ( empty( $orderby ) ) {
			return $sort;
		}

		if ( 'comment_agent' === $orderby ) {
			$sort[] = [
				'comment_agent.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_approved' === $orderby ) {
			$sort[] = [
				'comment_approved.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_author' === $orderby ) {
			$sort[] = [
				'comment_author.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_author_email' === $orderby ) {
			$sort[] = [
				'comment_author_email.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_author_IP' === $orderby ) {
			$sort[] = [
				'comment_author_IP.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_author_url' === $orderby ) {
			$sort[] = [
				'comment_author_url.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_content' === $orderby ) {
			$sort[] = [
				'comment_content.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_date' === $orderby ) {
			$sort[] = [
				'comment_date' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_date_gmt' === $orderby ) {
			$sort[] = [
				'comment_date_gmt' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_ID' === $orderby ) {
			$sort[] = [
				'comment_ID' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_karma' === $orderby ) {
			$sort[] = [
				'comment_karma' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_parent' === $orderby ) {
			$sort[] = [
				'comment_parent' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_post_ID' === $orderby ) {
			$sort[] = [
				'comment_post_ID' => [
					'order' => $order,
				],
			];
		} elseif ( 'comment_type' === $orderby ) {
			$sort[] = [
				'comment_type.raw' => [
					'order' => $order,
				],
			];
		} elseif ( 'user_id' === $orderby ) {
			$sort[] = [
				'user_id' => [
					'order' => $order,
				],
			];
		} elseif ( 'meta_value' === $orderby ) {
			if ( ! empty( $args['meta_key'] ) ) {
				$sort[] = [
					'meta.' . $args['meta_key'] . '.value' => [
						'order' => $order,
					],
				];
			}
		} elseif ( 'meta_value_num' === $orderby ) {
			if ( ! empty( $args['meta_key'] ) ) {
				$sort[] = [
					'meta.' . $args['meta_key'] . '.long' => [
						'order' => $order,
					],
				];
			}
		} else {
			$sort[] = [
				$orderby => [
					'order' => $order,
				],
			];
		}

		return $sort;
	}

}
