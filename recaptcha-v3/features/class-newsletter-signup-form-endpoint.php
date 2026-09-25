<?php
/**
 * Newsletter_Signup_Form_Endpoint class file
 *
 * @package courier
 */

namespace Courier_Plugin\Features;

use Alley\WP\Blocks\Named_Block;
use Alley\WP\Types\Feature;
use Courier_Plugin\Models\Post_Types\Regwall as Regwall_Model;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use WP_HTML_Tag_Processor;
use WP_Path_Dispatch\Path_Dispatch;
use WP_Post;
use WP_Query;
use WP_Sailthru_Client;

use function Courier_Plugin\render_pattern;

/**
 * Endpoint for single newsletter signup form submissions.
 */
final readonly class Newsletter_Signup_Form_Endpoint implements Feature {
	/**
	 * Constructor.
	 *
	 * @param Path_Dispatch                    $path_dispatch   Path Dispatch instance.
	 * @param WP_Sailthru_Client               $sailthru_client Sailthru client.
	 * @param Request                          $request         HTTP request.
	 * @param Newsletter_Signup_Outcome_Logger $outcome_logger Outcome logger.
	 */
	public function __construct(
		private Path_Dispatch $path_dispatch,
		private WP_Sailthru_Client $sailthru_client,
		private Request $request,
		private Newsletter_Signup_Outcome_Logger $outcome_logger,
	) {}

	/**
	 * Boot the feature.
	 */
	public function boot(): void {
		$this->path_dispatch->add_path(
			[
				'path'     => 'api/newsletter-signup-form',
				'callback' => $this->callback( ... ),
			],
		);
	}

	/**
	 * The callback for the endpoint.
	 */
	public function callback(): never {
		$request_id = wp_generate_uuid4();

		if ( $this->request->getMethod() !== 'POST' ) {
			$this->outcome_logger->log(
				[
					'request_id'           => $request_id,
					'endpoint'             => 'single',
					'outcome'              => 'method_not_allowed',
					'reason'               => '',
					'blog_id'              => get_current_blog_id(),
					'request_host'         => $this->request->getHost(),
					'signup_url_host'      => '',
					'signup_url_path'      => '',
					'signup_type'          => '',
					'regwall_name'         => '',
					'submitted_lists'      => [],
					'email'                => '',
					'utm_source'           => '',
					'utm_medium'           => '',
					'utm_campaign'         => '',
					'utm_term'             => '',
					'utm_content'          => '',
					'utm_id'               => '',
					'utm_source_platform'  => '',
					'utm_creative_format'  => '',
					'utm_marketing_tactic' => '',
					'recaptcha_result'     => 'not_checked',
					'sailthru_attempted'   => false,
					'sailthru_result'      => 'not_attempted',
				]
			);

			http_response_code( 405 );
			exit;
		}

		nocache_headers();

		$nonce = sanitize_text_field( wp_unslash( (string) $this->request->request->get( '_wpnonce' ) ) );

		if ( ! wp_verify_nonce( $nonce, 'courier_newsletter_signup_form' ) ) {
			$this->outcome_logger->log(
				[
					'request_id'           => $request_id,
					'endpoint'             => 'single',
					'outcome'              => 'nonce_failed',
					'reason'               => '',
					'blog_id'              => get_current_blog_id(),
					'request_host'         => $this->request->getHost(),
					'signup_url_host'      => '',
					'signup_url_path'      => '',
					'signup_type'          => '',
					'regwall_name'         => '',
					'submitted_lists'      => [],
					'email'                => '',
					'utm_source'           => '',
					'utm_medium'           => '',
					'utm_campaign'         => '',
					'utm_term'             => '',
					'utm_content'          => '',
					'utm_id'               => '',
					'utm_source_platform'  => '',
					'utm_creative_format'  => '',
					'utm_marketing_tactic' => '',
					'recaptcha_result'     => 'not_checked',
					'sailthru_attempted'   => false,
					'sailthru_result'      => 'not_attempted',
				]
			);
			http_response_code( 403 );
			exit;
		}

		// Honeypot check.
		$honeypot = (string) $this->request->request->get( 'website' );
		if ( $honeypot !== '' ) {
			$this->outcome_logger->log(
				[
					'request_id'           => $request_id,
					'endpoint'             => 'single',
					'outcome'              => 'honeypot_blocked',
					'reason'               => '',
					'blog_id'              => get_current_blog_id(),
					'request_host'         => $this->request->getHost(),
					'signup_url_host'      => '',
					'signup_url_path'      => '',
					'signup_type'          => '',
					'regwall_name'         => '',
					'submitted_lists'      => [],
					'email'                => '',
					'utm_source'           => '',
					'utm_medium'           => '',
					'utm_campaign'         => '',
					'utm_term'             => '',
					'utm_content'          => '',
					'utm_id'               => '',
					'utm_source_platform'  => '',
					'utm_creative_format'  => '',
					'utm_marketing_tactic' => '',
					'recaptcha_result'     => 'not_checked',
					'sailthru_attempted'   => false,
					'sailthru_result'      => 'not_attempted',
				]
			);
			http_response_code( 200 );
			header( 'HX-Trigger: honeypot_triggered' );
			exit;
		}

		http_response_code( 200 );

		$html    = '';
		$success = false;

		try {
			$author           = sanitize_text_field( wp_unslash( $this->request->request->getString( 'author' ) ) );
			$email            = sanitize_text_field( wp_unslash( $this->request->request->getString( 'email' ) ) );
			$list_name        = sanitize_text_field( wp_unslash( $this->request->request->getString( 'list_name' ) ) );
			$page_title       = sanitize_text_field( wp_unslash( $this->request->request->getString( 'page_title' ) ) );
			$previous_url     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'previous_url' ) ) );
			$referrer         = sanitize_text_field( wp_unslash( $this->request->request->getString( 'referrer' ) ) );
			$regwall_name     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'regwall_name' ) ) );
			$signup_url       = sanitize_text_field( wp_unslash( $this->request->request->getString( 'signup_url' ) ) );
			$utm_campaign     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_campaign' ) ) );
			$utm_content      = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_content' ) ) );
			$utm_medium       = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_medium' ) ) );
			$utm_source       = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_source' ) ) );
			$utm_term         = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_term' ) ) );
			$utm_id           = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_id' ) ) );
			$utm_platform     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_source_platform' ) ) );
			$utm_creative     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_creative_format' ) ) );
			$utm_tactic       = sanitize_text_field( wp_unslash( $this->request->request->getString( 'utm_marketing_tactic' ) ) );
			$wp_categories    = sanitize_text_field( wp_unslash( $this->request->request->getString( 'wp_categories' ) ) );
			$wp_series        = sanitize_text_field( wp_unslash( $this->request->request->getString( 'wp_series' ) ) );
			$wp_tags          = sanitize_text_field( wp_unslash( $this->request->request->getString( 'wp_tags' ) ) );
			$landing_referrer = sanitize_text_field( wp_unslash( $this->request->request->getString( 'landing_referrer' ) ) );
			$signup_type      = sanitize_text_field( wp_unslash( $this->request->request->getString( 'signup_type' ) ) );
			$ga_client_id     = sanitize_text_field( wp_unslash( $this->request->request->getString( 'ga_client_id' ) ) );
		} catch ( Exception ) {
			$author           = '';
			$email            = '';
			$list_name        = '';
			$page_title       = '';
			$previous_url     = '';
			$referrer         = '';
			$regwall_name     = '';
			$signup_url       = '';
			$utm_campaign     = '';
			$utm_content      = '';
			$utm_medium       = '';
			$utm_source       = '';
			$utm_term         = '';
			$utm_id           = '';
			$utm_platform     = '';
			$utm_creative     = '';
			$utm_tactic       = '';
			$wp_categories    = '';
			$wp_series        = '';
			$wp_tags          = '';
			$landing_referrer = '';
			$signup_type      = '';
			$ga_client_id     = '';
		}
		$utm_params = [
			'utm_source'           => $utm_source,
			'utm_medium'           => $utm_medium,
			'utm_campaign'         => $utm_campaign,
			'utm_term'             => $utm_term,
			'utm_content'          => $utm_content,
			'utm_id'               => $utm_id,
			'utm_source_platform'  => $utm_platform,
			'utm_creative_format'  => $utm_creative,
			'utm_marketing_tactic' => $utm_tactic,
		];

		// reCAPTCHA v3 verification.
		$form_attrs = [
			'author'       => $author,
			'listName'     => $list_name,
			'pageTitle'    => $page_title,
			'previous_url' => $previous_url,
			'referrer'     => $referrer,
			'signupUrl'    => $signup_url,
			'utmCampaign'  => $utm_campaign,
			'utmContent'   => $utm_content,
			'utmMedium'    => $utm_medium,
			'utmSource'    => $utm_source,
			'utmTerm'      => $utm_term,
			'wpCategories' => $wp_categories,
			'wpSeries'     => $wp_series,
			'wpTags'       => $wp_tags,
		];

		$recaptcha_token = sanitize_text_field( wp_unslash( (string) $this->request->request->get( 'recaptcha_token' ) ) );

		if ( ! $this->verify_recaptcha( $recaptcha_token ) ) {
			http_response_code( 200 );
			header( 'HX-Trigger: recaptcha_failed' );

			$this->outcome_logger->log(
				[
					'request_id'         => $request_id,
					'endpoint'           => 'single',
					'outcome'            => 'recaptcha_failed',
					'reason'             => '',
					'blog_id'            => get_current_blog_id(),
					'request_host'       => $this->request->getHost(),
					'signup_url_host'    => wp_parse_url( $signup_url, PHP_URL_HOST ),
					'signup_url_path'    => wp_parse_url( $signup_url, PHP_URL_PATH ),
					'signup_type'        => $signup_type,
					'regwall_name'       => $regwall_name,
					'submitted_lists'    => $list_name === '' ? [] : [ $list_name ],
					'email'              => $email,
					...$utm_params,
					'recaptcha_result'   => 'failed',
					'sailthru_attempted' => false,
					'sailthru_result'    => 'not_attempted',
				]
			);

			$block = new Named_Block(
				block_name: 'courier/newsletter-signup-form',
				attrs: [
					...$form_attrs,
					'errorMsg' => __( 'We couldn\'t verify your submission. Please try again, or email us if the problem continues.', 'courier' ),
				],
			);

			echo do_blocks( $block->serialized_blocks() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( ! $this->is_valid_signup_url_for_current_host( $signup_url ) ) {
			$this->log_invalid_submission(
				'invalid_signup_url_host',
				$signup_url,
				$referrer,
				$regwall_name,
				$utm_params,
				$email,
				[ $list_name ],
			);

			$this->outcome_logger->log(
				[
					'request_id'         => $request_id,
					'endpoint'           => 'single',
					'outcome'            => 'validation_blocked',
					'reason'             => 'invalid_signup_url_host',
					'blog_id'            => get_current_blog_id(),
					'request_host'       => $this->request->getHost(),
					'signup_url_host'    => wp_parse_url( $signup_url, PHP_URL_HOST ),
					'signup_url_path'    => wp_parse_url( $signup_url, PHP_URL_PATH ),
					'signup_type'        => $signup_type,
					'regwall_name'       => $regwall_name,
					'submitted_lists'    => $list_name === '' ? [] : [ $list_name ],
					'email'              => $email,
					...$utm_params,
					'recaptcha_result'   => 'passed',
					'sailthru_attempted' => false,
					'sailthru_result'    => 'not_attempted',
				]
			);

			$this->render_validation_error(
				$form_attrs,
				$regwall_name,
				$signup_type,
			);
		}

		if ( $list_name !== '' ) {
			$is_regwall = in_array( $signup_type, [ 'hardgate', 'softgate' ], true );

			$allowed_list_names = $is_regwall
				? $this->get_allowed_list_names_for_regwall( $regwall_name )
				: $this->get_allowed_list_names_for_signup_url( $signup_url );

			$invalid_list_names = $allowed_list_names === []
				? [ $list_name ]
				: array_values( array_diff( [ $list_name ], $allowed_list_names ) );

			if ( $invalid_list_names !== [] ) {
				$this->log_invalid_submission(
					'invalid_list_name',
					$signup_url,
					$referrer,
					$regwall_name,
					$utm_params,
					$email,
					[ $list_name ],
					$invalid_list_names,
				);

				$this->outcome_logger->log(
					[
						'request_id'         => $request_id,
						'endpoint'           => 'single',
						'outcome'            => 'validation_blocked',
						'reason'             => 'invalid_list_name',
						'blog_id'            => get_current_blog_id(),
						'request_host'       => $this->request->getHost(),
						'signup_url_host'    => wp_parse_url( $signup_url, PHP_URL_HOST ),
						'signup_url_path'    => wp_parse_url( $signup_url, PHP_URL_PATH ),
						'signup_type'        => $signup_type,
						'regwall_name'       => $regwall_name,
						'submitted_lists'    => [ $list_name ],
						'email'              => $email,
						...$utm_params,
						'recaptcha_result'   => 'passed',
						'sailthru_attempted' => false,
						'sailthru_result'    => 'not_attempted',
					]
				);

				$this->render_validation_error(
					$form_attrs,
					$regwall_name,
					$signup_type,
				);
			}
		}

		$source = empty( $utm_source ) ? explode( '?', $signup_url, 2 )[0] : $utm_source;

		$sailthru_attempted = false;
		$sailthru_result    = 'not_attempted';

		if ( is_email( $email ) && $list_name !== '' ) {
			try {
				$vars = [
					'page_title'       => $page_title,
					'referrer'         => $referrer,
					'signup_url'       => $signup_url,
					'source'           => $source,
					'wp_categories'    => $wp_categories,
					'wp_series'        => $wp_series,
					'wp_tags'          => $wp_tags,
					'landing_referrer' => $landing_referrer,
					'signup_type'      => $signup_type,
					'ga_client_id'     => $ga_client_id,
				];

				// Only send these if they have actual values.
				foreach ( [
					'author'       => $author,
					'campaign'     => $utm_campaign,
					'content'      => $utm_content,
					'medium'       => $utm_medium,
					'previous_url' => $previous_url,
					'term'         => $utm_term,
				] as $key => $value ) {
					if ( $value !== '' ) {
						$vars[ $key ] = $value;
					}
				}

				if ( $regwall_name !== '' ) {
					$vars['signup_form'] = $regwall_name;
				}

				$sailthru_attempted = true;
				$result             = $this->sailthru_client->saveUser(
					// Backcompat: User "ID" is email address.
					$email,
					[
						'lists' => [
							$list_name => 1,
						],
						'vars'  => $vars,
					],
				);

				$success         = is_array( $result ) && isset( $result['ok'] ) && $result['ok'] === true;
				$sailthru_result = $success ? 'success' : 'failure';
			} catch ( Exception $exception ) {
				$sailthru_result = 'exception';

				// Ignore.
				unset( $exception );
			}
		}

		if ( ! is_email( $email ) ) {
			$outcome = 'invalid_email';
		} elseif ( $list_name === '' ) {
			$outcome = 'empty_list';
		} elseif ( $sailthru_result === 'success' ) {
			$outcome = 'sailthru_success';
		} elseif ( $sailthru_result === 'exception' ) {
			$outcome = 'sailthru_exception';
		} else {
			$outcome = 'sailthru_failure';
		}

		$this->outcome_logger->log(
			[
				'request_id'         => $request_id,
				'endpoint'           => 'single',
				'outcome'            => $outcome,
				'reason'             => '',
				'blog_id'            => get_current_blog_id(),
				'request_host'       => $this->request->getHost(),
				'signup_url_host'    => wp_parse_url( $signup_url, PHP_URL_HOST ),
				'signup_url_path'    => wp_parse_url( $signup_url, PHP_URL_PATH ),
				'signup_type'        => $signup_type,
				'regwall_name'       => $regwall_name,
				'submitted_lists'    => $list_name === '' ? [] : [ $list_name ],
				'email'              => $email,
				...$utm_params,
				'recaptcha_result'   => 'passed',
				'sailthru_attempted' => $sailthru_attempted,
				'sailthru_result'    => $sailthru_result,
			]
		);

		if ( $success ) {
			header( 'HX-Trigger: newsletter_signup_success' );
			$html = render_pattern( 'courier/subscribed' );
		}

		if ( ! $success ) {
			$error_msg = $list_name === ''
				? __( 'You must select a newsletter to subscribe to.', 'courier' )
				: __( 'There was an error subscribing you. Please try again later.', 'courier' );

			$block = new Named_Block(
				block_name: 'courier/newsletter-signup-form',
				attrs: [
					'author'       => $author,
					'errorMsg'     => $error_msg,
					'listName'     => $list_name,
					'pageTitle'    => $page_title,
					'previous_url' => $previous_url,
					'referrer'     => $referrer,
					'signupUrl'    => $signup_url,
					'utmCampaign'  => $utm_campaign,
					'utmContent'   => $utm_content,
					'utmMedium'    => $utm_medium,
					'utmSource'    => $utm_source,
					'utmTerm'      => $utm_term,
					'wpCategories' => $wp_categories,
					'wpSeries'     => $wp_series,
					'wpTags'       => $wp_tags,
				],
			);

			$html = do_blocks( $block->serialized_blocks() );

			if ( $email !== '' ) {
				$proc = new WP_HTML_Tag_Processor( $html );

				while ( $proc->next_tag( [ 'tag_name' => 'input' ] ) ) {
					$type = $proc->get_attribute( 'type' );

					if ( $type === 'email' ) {
						$proc->set_attribute( 'value', $email );
						break;
					}
				}

				$html = $proc->get_updated_html();
			}
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Check whether the submitted signup URL belongs to the current host.
	 *
	 * @param string $signup_url Submitted signup URL.
	 * @return bool Whether the signup URL is valid.
	 */
	private function is_valid_signup_url_for_current_host( string $signup_url ): bool {
		$signup_url_host = wp_parse_url( $signup_url, PHP_URL_HOST );

		if ( ! is_string( $signup_url_host ) || $signup_url_host === '' ) {
			return false;
		}

		return strtolower( $signup_url_host ) === strtolower( $this->request->getHost() );
	}

	/**
	 * Get allowed newsletter list names for a published regwall.
	 *
	 * The frontend currently submits the regwall title as regwall_name,
	 * so resolve the regwall by its exact published title.
	 *
	 * Require exactly one match so an ambiguous title fails closed.
	 *
	 * @param string $regwall_name Submitted regwall name.
	 * @return array<int, string> Allowed Sailthru list names.
	 */
	private function get_allowed_list_names_for_regwall( string $regwall_name ): array {
		$query = new WP_Query(
			[
				'post_type'              => Regwall_Model::$object_name,
				'post_status'            => 'publish',
				'title'                  => $regwall_name,
				'posts_per_page'         => 2,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			],
		);

		if ( count( $query->posts ) !== 1 ) {
			return [];
		}

		$regwall = $query->posts[0];

		if ( ! $regwall instanceof WP_Post || ! is_string( $regwall->post_content ) || $regwall->post_content === '' ) {
			return [];
		}

		return $this->get_list_names_from_blocks(
			array_values( parse_blocks( $regwall->post_content ) ),
		);
	}

	/**
	 * Get allowed newsletter list names from the submitted signup URL.
	 *
	 * @param string $signup_url Submitted signup URL.
	 * @return array<int, string> Allowed Sailthru list names.
	 */
	private function get_allowed_list_names_for_signup_url( string $signup_url ): array {
		$post_id = $this->get_post_id_for_signup_url( $signup_url );

		if ( $post_id <= 0 ) {
			return [];
		}

		$content = get_post_field( 'post_content', $post_id );

		if ( ! is_string( $content ) || $content === '' ) {
			return [];
		}

		return $this->get_list_names_from_blocks(
			array_values( parse_blocks( $content ) ),
		);
	}

	/**
	 * Get a post ID from a signup URL.
	 *
	 * @param string $signup_url Submitted signup URL.
	 * @return int Post ID, or zero if not found.
	 */
	private function get_post_id_for_signup_url( string $signup_url ): int {
		$post_id = 0;

		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			$post_id = (int) wpcom_vip_url_to_postid( $signup_url ); // @phpstan-ignore-line
		}

		if ( $post_id > 0 ) {
			return $post_id;
		}

		$path = wp_parse_url( $signup_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || $path === '' ) {
			return 0;
		}

		$post = get_page_by_path(
			trim( $path, '/' ),
			OBJECT,
			get_post_types( [ 'public' => true ] ),
		);

		return $post instanceof WP_Post ? (int) $post->ID : 0;
	}

	/**
	 * Get newsletter list names from parsed blocks.
	 *
	 * Supports both single newsletter signup blocks and newsletter options
	 * used within multi-newsletter/regwall configurations.
	 *
	 * @param array<mixed>    $blocks Parsed blocks.
	 * @param array<int, int> $seen_refs Reusable block IDs already parsed.
	 * @return array<int, string> Newsletter list names.
	 */
	private function get_list_names_from_blocks( array $blocks, array $seen_refs = [] ): array {
		$list_names = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = isset( $block['blockName'] ) && is_string( $block['blockName'] )
				? $block['blockName']
				: '';

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] )
				? $block['attrs']
				: [];

			if (
				in_array(
					$block_name,
					[
						'courier/newsletter-signup-form',
						'courier/multi-newsletter-option',
					],
					true,
				)
				&& isset( $attrs['listName'] )
				&& is_string( $attrs['listName'] )
				&& $attrs['listName'] !== ''
			) {
				$list_names[] = sanitize_text_field( $attrs['listName'] );
			}

			if (
				$block_name === 'core/block'
				&& isset( $attrs['ref'] )
				&& is_numeric( $attrs['ref'] )
			) {
				$ref = (int) $attrs['ref'];

				if ( $ref > 0 && ! in_array( $ref, $seen_refs, true ) ) {
					$reusable_block = get_post( $ref );

					if (
						$reusable_block instanceof WP_Post
						&& is_string( $reusable_block->post_content )
					) {
						$list_names = array_merge(
							$list_names,
							$this->get_list_names_from_blocks(
								array_values( parse_blocks( $reusable_block->post_content ) ),
								array_merge( $seen_refs, [ $ref ] ),
							),
						);
					}
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$list_names = array_merge(
					$list_names,
					$this->get_list_names_from_blocks(
						array_values( $block['innerBlocks'] ),
						$seen_refs,
					),
				);
			}
		}

		return array_values( array_unique( $list_names ) );
	}

	/**
	 * Log a blocked newsletter signup submission.
	 *
	 * @param string                $reason Invalid submission reason.
	 * @param string                $signup_url Submitted signup URL.
	 * @param string                $referrer Submitted referrer.
	 * @param string                $regwall_name Submitted regwall name.
	 * @param array<string, string> $utm_params Submitted UTM parameters.
	 * @param string                $email Submitted email.
	 * @param array<int, string>    $list_names Submitted list names.
	 * @param array<int, string>    $invalid_list_names Invalid list names.
	 */
	private function log_invalid_submission(
		string $reason,
		string $signup_url,
		string $referrer,
		string $regwall_name,
		array $utm_params,
		string $email,
		array $list_names,
		array $invalid_list_names = [],
	): void {
		$message = wp_json_encode(
			[
				'event'           => 'courier_newsletter_validation_blocked',
				'endpoint'        => 'newsletter_signup_form',
				'form_type'       => 'single',
				'reason'          => $reason,
				'blog_id'         => get_current_blog_id(),
				'request_host'    => $this->request->getHost(),
				'signup_url_host' => wp_parse_url( $signup_url, PHP_URL_HOST ),
				'signup_url_path' => wp_parse_url( $signup_url, PHP_URL_PATH ),
				'referrer_host'   => wp_parse_url( $referrer, PHP_URL_HOST ),
				'regwall_name'    => $regwall_name,
				'utm_source'      => $utm_params['utm_source'] ?? '',
				'utm_medium'      => $utm_params['utm_medium'] ?? '',
				'utm_campaign'    => $utm_params['utm_campaign'] ?? '',
				'utm_term'        => $utm_params['utm_term'] ?? '',
				'utm_content'     => $utm_params['utm_content'] ?? '',
				'submitted_lists' => array_values( $list_names ),
				'invalid_lists'   => array_values( $invalid_list_names ),
				'email'           => $email,
			],
		);

		if ( false === $message ) {
			return;
		}

		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Render a visible error for a blocked signup.
	 *
	 * A successful HTTP response is intentional here so HTMX replaces the
	 * form instead of treating the request as an unhandled HTTP error.
	 *
	 * @param array<string, mixed> $form_attrs   Newsletter form attributes.
	 * @param string               $regwall_name Submitted regwall name.
	 * @param string               $signup_type  Submitted signup type.
	 * @return never
	 */
	private function render_validation_error(
		array $form_attrs,
		string $regwall_name,
		string $signup_type,
	): never {
		http_response_code( 200 );
		header( 'HX-Trigger: newsletter_signup_validation_failed' );

		$block = new Named_Block(
			block_name: 'courier/newsletter-signup-form',
			attrs: [
				...$form_attrs,
				'errorMsg' => __( 'We couldn\'t process your subscription. Please try again, or email us if the problem continues.', 'courier' ),
			],
		);

		$html = do_blocks( $block->serialized_blocks() );
		$proc = new WP_HTML_Tag_Processor( $html );

		while ( $proc->next_tag( [ 'tag_name' => 'input' ] ) ) {
			$name = $proc->get_attribute( 'name' );

			if ( $name === 'regwall_name' ) {
				$proc->set_attribute( 'value', $regwall_name );
			}

			if ( $name === 'signup_type' ) {
				$proc->set_attribute( 'value', $signup_type );
			}
		}

		echo $proc->get_updated_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Verify a reCAPTCHA v3 token.
	 *
	 * @param string $token The token from the client.
	 * @return bool
	 */
	private function verify_recaptcha( string $token ): bool {
		$secret = vip_get_env_var( 'COURIER_RECAPTCHA_SECRET_KEY', '' );

		if ( ! is_string( $secret ) || $secret === '' ) {
			return wp_get_environment_type() === 'local';
		}

		if ( $token === '' ) {
			return false;
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			[
				'body' => [
					'secret'   => $secret,
					'response' => $token,
				],
			],
		);

		if ( is_wp_error( $response ) ) {
			header( 'X-Recaptcha-Errors: google-request-failed' );
			return true; // Fail open on network error.
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) ) {
			header( 'X-Recaptcha-Success: ' . ( empty( $body['success'] ) ? 'false' : 'true' ) );

			if ( isset( $body['score'] ) && is_numeric( $body['score'] ) ) {
				header( 'X-Recaptcha-Score: ' . $body['score'] );
			}

			if ( ! empty( $body['error-codes'] ) && is_array( $body['error-codes'] ) ) {
				$codes = array_filter( $body['error-codes'], is_string( ... ) );
				header( 'X-Recaptcha-Errors: ' . sanitize_text_field( implode( ',', $codes ) ) );
			}
		}

		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return false;
		}

		$score = isset( $body['score'] ) && is_numeric( $body['score'] ) ? (float) $body['score'] : 0.0;

		return $score >= 0.5;
	}
}
