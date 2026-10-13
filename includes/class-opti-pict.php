<?php
/**
 * Main plugin controller.
 *
 * @package OptiPict
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the admin interface and image conversion workflow.
 */
final class Opti_Pict {
	const NONCE_ACTION       = 'opti_pict_admin';
	const BACKUP_META        = '_opti_pict_backup';
	const PROCESSED_META     = '_opti_pict_processed';
	const LAST_REPORT_OPTION = 'opti_pict_last_report';
	const BATCH_SIZE         = 20;

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Pict|null
	 */
	private static $instance = null;

	/**
	 * Attachment IDs resolved from original URLs during the current request.
	 *
	 * @var array<string,int>
	 */
	private $source_lookup = array();

	/**
	 * Get the plugin instance.
	 *
	 * @return Opti_Pict
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_opti_pict_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_opti_pict_optimize', array( $this, 'ajax_optimize' ) );
		add_action( 'wp_ajax_opti_pict_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_opti_pict_save_report', array( $this, 'ajax_save_report' ) );
		add_action( 'template_redirect', array( $this, 'start_frontend_image_buffer' ), 0 );
		add_filter( 'wp_content_img_tag', array( $this, 'use_optimized_content_image' ), 20, 3 );
		add_filter( 'the_content', array( $this, 'use_optimized_images_in_html' ), 99 );
		add_filter( 'render_block', array( $this, 'use_optimized_images_in_html' ), 99 );
		add_filter( 'widget_text_content', array( $this, 'use_optimized_images_in_html' ), 99 );
		add_filter( 'post_thumbnail_html', array( $this, 'use_optimized_images_in_html' ), 99 );
		add_filter( 'elementor/frontend/the_content', array( $this, 'use_optimized_images_in_html' ), 99 );
		add_filter( 'elementor/widget/render_content', array( $this, 'use_optimized_images_in_html' ), 99 );
	}

	/**
	 * Start a final HTML pass for theme areas outside normal content filters.
	 *
	 * Headers, footers and some page builders print attachment markup directly.
	 * Buffering only public HTML requests lets those image tags use the same safe
	 * URL replacement as filtered post content.
	 */
	public function start_frontend_image_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_trackback() || is_robots() ) {
			return;
		}

		ob_start( array( $this, 'rewrite_frontend_document' ) );
	}

	/**
	 * Replace optimized image URLs in a complete frontend document.
	 *
	 * @param string $html Complete response body.
	 * @return string
	 */
	public function rewrite_frontend_document( $html ) {
		return $this->replace_optimized_images_in_html( $html );
	}

	/**
	 * Replace static URLs in existing image blocks without editing post content.
	 *
	 * WordPress stores the image URL in block markup. Attachment metadata may point
	 * to WebP after optimization while that stored URL still points to the original.
	 *
	 * @param string $image         Complete image HTML tag.
	 * @param string $context       Rendering context.
	 * @param int    $attachment_id Attachment ID, or zero when unknown.
	 * @return string
	 */
	public function use_optimized_content_image( $image, $context, $attachment_id ) {
		unset( $context );
		return $this->replace_optimized_images_in_html( $image, absint( $attachment_id ) );
	}

	/**
	 * Replace original image URLs in rendered content and page-builder widgets.
	 *
	 * The second argument deliberately accepts optional filter context used by some
	 * builders. It is not trusted or required for URL resolution.
	 *
	 * @param string $html    Rendered HTML.
	 * @param mixed  $context Optional filter context.
	 * @return string
	 */
	public function use_optimized_images_in_html( $html, $context = null ) {
		unset( $context );
		return $this->replace_optimized_images_in_html( $html );
	}

	/**
	 * Rewrite image attributes that still point to an original JPEG or PNG.
	 *
	 * @param string $html                 Rendered HTML.
	 * @param int    $forced_attachment_id Attachment ID supplied by WordPress.
	 * @return string
	 */
	private function replace_optimized_images_in_html( $html, $forced_attachment_id = 0 ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'img' ) ) {
			$attachment_id = $forced_attachment_id ? $forced_attachment_id : $this->get_attachment_id_from_tag( $processor );
			if ( $attachment_id ) {
				$this->replace_current_image_tag( $processor, $attachment_id );
			}

			if ( $forced_attachment_id ) {
				break;
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Resolve an attachment from standard classes, builder attributes or its URL.
	 *
	 * @param WP_HTML_Tag_Processor $processor HTML processor on an image tag.
	 * @return int
	 */
	private function get_attachment_id_from_tag( $processor ) {
		$class_name = $processor->get_attribute( 'class' );
		if ( is_string( $class_name ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class_name, $matches ) ) {
			return absint( $matches[1] );
		}

		foreach ( array( 'data-attachment-id', 'data-id' ) as $attribute ) {
			$attachment_id = absint( $processor->get_attribute( $attribute ) );
			if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) {
			$source = $processor->get_attribute( $attribute );
			if ( is_string( $source ) && '' !== $source ) {
				$attachment_id = $this->find_attachment_by_original_source( $source );
				if ( $attachment_id ) {
					return $attachment_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Replace URL-bearing attributes on the current image tag.
	 *
	 * @param WP_HTML_Tag_Processor $processor    HTML processor on an image tag.
	 * @param int                   $attachment_id Attachment ID.
	 * @return bool Whether at least one original URL was replaced.
	 */
	private function replace_current_image_tag( $processor, $attachment_id ) {
		$backup = get_post_meta( $attachment_id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) || empty( $backup['attached_file'] ) ) {
			return false;
		}

		$requested_size    = false;
		$replaced_src      = false;
		$source_attributes = array( 'src', 'data-src', 'data-lazy-src', 'data-original' );
		foreach ( $source_attributes as $attribute ) {
			$source = $processor->get_attribute( $attribute );
			if ( ! is_string( $source ) || '' === $source ) {
				continue;
			}

			$attribute_size = $this->get_original_image_size( $backup, $source, true );
			if ( false === $attribute_size ) {
				continue;
			}

			$optimized_url = wp_get_attachment_image_url( $attachment_id, $attribute_size );
			if ( ! $optimized_url ) {
				continue;
			}

			$processor->set_attribute( $attribute, $optimized_url );
			$requested_size = false === $requested_size ? $attribute_size : $requested_size;
			$replaced_src   = $replaced_src || 'src' === $attribute;
		}

		if ( false === $requested_size ) {
			return false;
		}

		$srcset = wp_get_attachment_image_srcset( $attachment_id, $requested_size );
		if ( $srcset ) {
			if ( $replaced_src || null !== $processor->get_attribute( 'srcset' ) ) {
				$processor->set_attribute( 'srcset', $srcset );
			}
			foreach ( array( 'data-srcset', 'data-lazy-srcset' ) as $attribute ) {
				if ( null !== $processor->get_attribute( $attribute ) ) {
					$processor->set_attribute( $attribute, $srcset );
				}
			}
		} else {
			$processor->remove_attribute( 'srcset' );
			$processor->remove_attribute( 'data-srcset' );
			$processor->remove_attribute( 'data-lazy-srcset' );
		}

		return true;
	}

	/**
	 * Determine which original registered image size a URL represents.
	 *
	 * @param array<string,mixed> $backup         Original attachment data.
	 * @param string              $source         Image URL.
	 * @param bool                $allow_filename Whether a trusted attachment ID allows filename fallback.
	 * @return string|false
	 */
	private function get_original_image_size( $backup, $source, $allow_filename = false ) {
		$source_path = $this->get_url_path( $source );
		if ( false === $source_path ) {
			return false;
		}

		$attached_file = ltrim( wp_normalize_path( $backup['attached_file'] ), '/' );
		if ( $this->path_matches_upload_file( $source_path, $attached_file ) ) {
			return 'full';
		}

		if ( ! empty( $backup['metadata']['sizes'] ) && is_array( $backup['metadata']['sizes'] ) ) {
			$directory = dirname( $attached_file );
			foreach ( $backup['metadata']['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}
				$relative_path = ( '.' === $directory ? '' : trailingslashit( $directory ) ) . wp_basename( $size_data['file'] );
				if ( $this->path_matches_upload_file( $source_path, $relative_path ) ) {
					return sanitize_key( $size_name );
				}
			}
		}

		if ( $allow_filename ) {
			$source_filename = wp_basename( $source_path );
			if ( wp_basename( $attached_file ) === $source_filename ) {
				return 'full';
			}

			foreach ( (array) ( $backup['metadata']['sizes'] ?? array() ) as $size_name => $size_data ) {
				if ( ! empty( $size_data['file'] ) && wp_basename( $size_data['file'] ) === $source_filename ) {
					return sanitize_key( $size_name );
				}
			}
		}

		return false;
	}

	/**
	 * Resolve a static original URL to an optimized attachment.
	 *
	 * @param string $source Image URL.
	 * @return int
	 */
	private function find_attachment_by_original_source( $source ) {
		$source_path = $this->get_url_path( $source );
		if ( false === $source_path ) {
			return 0;
		}

		$cache_key = md5( $source_path );
		if ( array_key_exists( $cache_key, $this->source_lookup ) ) {
			return $this->source_lookup[ $cache_key ];
		}

		$attachment_id = attachment_url_to_postid( $source );
		if ( $attachment_id ) {
			$backup = get_post_meta( $attachment_id, self::BACKUP_META, true );
			if ( is_array( $backup ) && false !== $this->get_original_image_size( $backup, $source ) ) {
				$this->source_lookup[ $cache_key ] = absint( $attachment_id );
				return $this->source_lookup[ $cache_key ];
			}
		}

		$filename = wp_basename( $source_path );
		if ( ! preg_match( '/\.(?:jpe?g|png)$/i', $filename ) ) {
			$this->source_lookup[ $cache_key ] = 0;
			return 0;
		}

		// A verified fallback is needed for builders that store only the old URL.
		$candidates = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				// Serialized backup values obtain a small candidate set; every match is verified below.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => self::BACKUP_META,
						'value'   => $filename,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$matches = array();
		foreach ( $candidates as $candidate_id ) {
			$backup = get_post_meta( $candidate_id, self::BACKUP_META, true );
			if ( is_array( $backup ) && false !== $this->get_original_image_size( $backup, $source ) ) {
				$matches[] = absint( $candidate_id );
			}
		}

		$this->source_lookup[ $cache_key ] = 1 === count( $matches ) ? $matches[0] : 0;
		return $this->source_lookup[ $cache_key ];
	}

	/**
	 * Extract a normalized URL path without query parameters.
	 *
	 * @param string $url Image URL.
	 * @return string|false
	 */
	private function get_url_path( $url ) {
		$path = wp_parse_url( html_entity_decode( $url ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? wp_normalize_path( rawurldecode( $path ) ) : false;
	}

	/**
	 * Check that a URL path ends with a specific relative uploads path.
	 *
	 * @param string $source_path   URL path.
	 * @param string $relative_path Relative uploads path.
	 * @return bool
	 */
	private function path_matches_upload_file( $source_path, $relative_path ) {
		$source_path   = '/' . ltrim( wp_normalize_path( $source_path ), '/' );
		$relative_path = '/' . ltrim( wp_normalize_path( $relative_path ), '/' );
		return strlen( $source_path ) >= strlen( $relative_path ) && substr( $source_path, -strlen( $relative_path ) ) === $relative_path;
	}

	/**
	 * Add the page under Media.
	 */
	public function register_admin_page() {
		add_media_page(
			__( 'Opti Pict', 'opti-pict' ),
			__( 'Opti Pict', 'opti-pict' ),
			'manage_options',
			'opti-pict',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Load assets only on this plugin screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'media_page_opti-pict' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'opti-pict-admin',
			OPTI_PICT_URL . 'assets/admin.css',
			array(),
			OPTI_PICT_VERSION
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'performance';
		if ( 'seo' === $section ) {
			return;
		}

		wp_enqueue_script(
			'opti-pict-admin',
			OPTI_PICT_URL . 'assets/admin.js',
			array(),
			OPTI_PICT_VERSION,
			true
		);

		wp_localize_script(
			'opti-pict-admin',
			'optiPict',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'batchSize' => self::BATCH_SIZE,
				'speedMbps' => 10,
				'strings'   => array(
					'networkError'     => __( 'La requête a échoué. Vérifiez votre connexion puis réessayez.', 'opti-pict' ),
					'scanInProgress'   => __( 'Analyse de la médiathèque…', 'opti-pict' ),
					'optimizeProgress' => __( 'Optimisation en cours…', 'opti-pict' ),
					'restoreProgress'  => __( 'Restauration en cours…', 'opti-pict' ),
					'restoreConfirm'   => __( 'Restaurer les images d’origine ? Les versions optimisées créées par Opti Pict seront supprimées.', 'opti-pict' ),
					'restoreOneConfirm' => __( 'Restaurer cette image dans son format d’origine ?', 'opti-pict' ),
					'complete'         => __( 'Traitement terminé.', 'opti-pict' ),
					'noImage'          => __( 'Aucune image JPEG ou PNG à optimiser.', 'opti-pict' ),
				),
			)
		);
	}

	/**
	 * Render the administration page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas l’autorisation d’accéder à cette page.', 'opti-pict' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$section      = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'performance';
		$section      = in_array( $section, array( 'performance', 'seo' ), true ) ? $section : 'performance';
		$is_seo       = 'seo' === $section;
		$page_url     = admin_url( 'upload.php?page=opti-pict' );
		$heading      = $is_seo ? __( 'Des images utiles au référencement et accessibles', 'opti-pict' ) : __( 'Des images plus légères, en toute simplicité', 'opti-pict' );
		$introduction = $is_seo ? __( 'Analysez le contexte de vos images, obtenez des propositions assistées par IA, puis validez chaque modification.', 'opti-pict' ) : __( 'Analysez vos JPEG et PNG, confirmez l’optimisation, puis mesurez le poids réellement économisé.', 'opti-pict' );
		?>
		<div class="wrap opti-pict" id="opti-pict-app">
			<header class="opti-pict__header">
				<div>
					<p class="opti-pict__eyebrow"><?php esc_html_e( 'Médiathèque WordPress', 'opti-pict' ); ?></p>
					<h1><?php echo esc_html( $heading ); ?></h1>
					<p class="opti-pict__lead"><?php echo esc_html( $introduction ); ?></p>
				</div>
				<div class="opti-pict__mark" aria-hidden="true">OP</div>
			</header>

			<nav class="nav-tab-wrapper opti-pict-tabs" aria-label="<?php esc_attr_e( 'Sections Opti Pict', 'opti-pict' ); ?>">
				<a class="nav-tab <?php echo $is_seo ? '' : 'nav-tab-active'; ?>" href="<?php echo esc_url( add_query_arg( 'section', 'performance', $page_url ) ); ?>"><?php esc_html_e( 'Poids des images', 'opti-pict' ); ?></a>
				<a class="nav-tab <?php echo $is_seo ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'section', 'seo', $page_url ) ); ?>"><?php esc_html_e( 'Référencement et textes alternatifs', 'opti-pict' ); ?></a>
			</nav>

			<?php
			if ( $is_seo ) {
				Opti_Pict_AI::instance()->render_panel();
				echo '</div>';
				return;
			}

			$support      = $this->get_support_status();
			$restore_ids   = $this->get_restore_ids();
			$restore_items = $this->get_restore_items( $restore_ids );
			$last_report   = get_option( self::LAST_REPORT_OPTION, array() );
			$can_optimize  = ( $support['webp'] || $support['avif'] ) && $support['uploads_writable'];
			?>

			<?php if ( ! $can_optimize ) : ?>
				<div class="notice notice-error inline opti-pict__notice" role="alert">
					<p>
						<?php
						if ( ! $support['webp'] && ! $support['avif'] ) {
							esc_html_e( 'Ce serveur ne permet pas encore de créer des fichiers WebP ou AVIF. Activez l’un de ces formats dans GD ou Imagick avant de lancer une optimisation.', 'opti-pict' );
						} else {
							esc_html_e( 'Le dossier des téléversements n’est pas accessible en écriture.', 'opti-pict' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<main class="opti-pict__grid">
				<section class="opti-pict-card opti-pict-card--primary" aria-labelledby="opti-pict-action-title">
					<div class="opti-pict-card__head">
						<span class="opti-pict-step" aria-hidden="true">1</span>
						<div>
							<h2 id="opti-pict-action-title"><?php esc_html_e( 'Analyser les images', 'opti-pict' ); ?></h2>
							<p><?php esc_html_e( 'Aucun fichier n’est modifié pendant l’analyse.', 'opti-pict' ); ?></p>
						</div>
					</div>

					<div id="opti-pict-idle">
						<ul class="opti-pict-checks" aria-label="<?php esc_attr_e( 'Périmètre de l’analyse', 'opti-pict' ); ?>">
							<li><?php esc_html_e( 'Images JPEG et PNG de la médiathèque', 'opti-pict' ); ?></li>
							<li><?php esc_html_e( 'Originaux conservés pour une restauration sûre', 'opti-pict' ); ?></li>
							<li><?php esc_html_e( 'Aucun fichier envoyé vers un service externe', 'opti-pict' ); ?></li>
						</ul>
						<fieldset class="opti-pict-format">
							<legend><?php esc_html_e( 'Format de sortie', 'opti-pict' ); ?></legend>
							<label><input type="radio" name="opti-pict-format" value="webp" <?php checked( $support['webp'] ); ?> <?php disabled( ! $support['webp'] ); ?>> <?php esc_html_e( 'WebP — compatible et léger', 'opti-pict' ); ?></label>
							<label><input type="radio" name="opti-pict-format" value="avif" <?php checked( ! $support['webp'] && $support['avif'] ); ?> <?php disabled( ! $support['avif'] ); ?>> <?php esc_html_e( 'AVIF — compression renforcée', 'opti-pict' ); ?></label>
							<?php if ( ! $support['avif'] ) : ?><p class="description"><?php esc_html_e( 'AVIF n’est pas disponible avec la bibliothèque d’images de ce serveur.', 'opti-pict' ); ?></p><?php endif; ?>
						</fieldset>
						<button type="button" class="button button-primary button-hero" id="opti-pict-scan" <?php disabled( ! $can_optimize ); ?>>
							<?php esc_html_e( 'Lancer l’analyse', 'opti-pict' ); ?>
						</button>
					</div>

					<div class="opti-pict-progress" id="opti-pict-progress" hidden>
						<div class="opti-pict-progress__row">
							<strong id="opti-pict-progress-label"><?php esc_html_e( 'Analyse en cours…', 'opti-pict' ); ?></strong>
							<span id="opti-pict-progress-value">0 %</span>
						</div>
						<progress id="opti-pict-progress-bar" max="100" value="0">0 %</progress>
						<p class="description" id="opti-pict-progress-detail"></p>
					</div>

					<div id="opti-pict-scan-result" hidden>
						<div class="opti-pict-card__head opti-pict-card__head--result">
							<span class="opti-pict-step" aria-hidden="true">2</span>
							<div>
								<h2><?php esc_html_e( 'Confirmer l’optimisation', 'opti-pict' ); ?></h2>
								<p id="opti-pict-scan-summary"></p>
							</div>
						</div>
						<div class="opti-pict-stats" aria-label="<?php esc_attr_e( 'Estimation avant optimisation', 'opti-pict' ); ?>">
							<div><span><?php esc_html_e( 'Poids actuel', 'opti-pict' ); ?></span><strong id="opti-pict-before">—</strong></div>
							<div><span><?php esc_html_e( 'Gain estimé', 'opti-pict' ); ?></span><strong id="opti-pict-estimated-saving">—</strong></div>
							<div><span><?php esc_html_e( 'Transfert cumulé estimé', 'opti-pict' ); ?></span><strong id="opti-pict-estimated-time">—</strong></div>
						</div>
						<p class="opti-pict-info"><?php esc_html_e( 'Temps de transfert cumulé des fichiers analysés, estimé à 10 Mbit/s, hors latence et cache. Le gain final dépend du contenu de chaque image.', 'opti-pict' ); ?></p>
						<div class="opti-pict-actions">
							<button type="button" class="button button-primary button-hero" id="opti-pict-optimize">
								<?php esc_html_e( 'Oui, optimiser ces images', 'opti-pict' ); ?>
							</button>
							<button type="button" class="button" id="opti-pict-rescan"><?php esc_html_e( 'Recommencer l’analyse', 'opti-pict' ); ?></button>
						</div>
					</div>

					<div id="opti-pict-final" hidden>
						<div class="opti-pict-success" role="status">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<div><h2 id="opti-pict-final-title" tabindex="-1"><?php esc_html_e( 'Optimisation terminée', 'opti-pict' ); ?></h2><p id="opti-pict-final-summary"></p></div>
						</div>
						<div class="opti-pict-stats opti-pict-stats--final">
							<div><span><?php esc_html_e( 'Poids économisé', 'opti-pict' ); ?></span><strong id="opti-pict-saved">—</strong></div>
							<div><span><?php esc_html_e( 'Réduction réelle', 'opti-pict' ); ?></span><strong id="opti-pict-percent">—</strong></div>
							<div><span><?php esc_html_e( 'Temps économisé', 'opti-pict' ); ?></span><strong id="opti-pict-time-saved">—</strong></div>
						</div>
						<p class="opti-pict-info"><?php esc_html_e( 'Sur un site en production, purgez ensuite le cache de page et le CDN pour que le HTML contenant les nouvelles URL soit régénéré.', 'opti-pict' ); ?></p>
						<button type="button" class="button button-primary" id="opti-pict-new-scan"><?php esc_html_e( 'Analyser à nouveau', 'opti-pict' ); ?></button>
					</div>

					<section class="opti-pict-optimized" id="opti-pict-optimized" <?php echo empty( $restore_items ) ? 'hidden' : ''; ?>>
						<div class="opti-pict-seo__results-head">
							<h2><?php esc_html_e( 'Images optimisées', 'opti-pict' ); ?></h2>
							<p><?php esc_html_e( 'Gain réel et restauration individuelle', 'opti-pict' ); ?></p>
						</div>
						<div class="opti-pict-optimized__list" id="opti-pict-optimized-list">
							<?php foreach ( $restore_items as $item ) : ?>
								<?php $this->render_restore_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</section>

					<div class="notice notice-error inline opti-pict__notice" id="opti-pict-error" role="alert" hidden><p></p></div>
					<p class="screen-reader-text" id="opti-pict-live" aria-live="polite" aria-atomic="true"></p>
				</section>

				<aside class="opti-pict-sidebar" aria-label="<?php esc_attr_e( 'Informations', 'opti-pict' ); ?>">
					<section class="opti-pict-card">
						<h2><?php esc_html_e( 'État du serveur', 'opti-pict' ); ?></h2>
						<ul class="opti-pict-status">
							<li><span><?php esc_html_e( 'Création WebP', 'opti-pict' ); ?></span><strong class="<?php echo $support['webp'] ? 'is-ok' : 'is-error'; ?>"><?php echo $support['webp'] ? esc_html__( 'Disponible', 'opti-pict' ) : esc_html__( 'Indisponible', 'opti-pict' ); ?></strong></li>
							<li><span><?php esc_html_e( 'Création AVIF', 'opti-pict' ); ?></span><strong class="<?php echo $support['avif'] ? 'is-ok' : ''; ?>"><?php echo $support['avif'] ? esc_html__( 'Disponible', 'opti-pict' ) : esc_html__( 'Indisponible', 'opti-pict' ); ?></strong></li>
							<li><span><?php esc_html_e( 'Dossier uploads', 'opti-pict' ); ?></span><strong class="<?php echo $support['uploads_writable'] ? 'is-ok' : 'is-error'; ?>"><?php echo $support['uploads_writable'] ? esc_html__( 'Accessible', 'opti-pict' ) : esc_html__( 'Bloqué', 'opti-pict' ); ?></strong></li>
							<li><span><?php esc_html_e( 'Qualité de sortie', 'opti-pict' ); ?></span><strong><?php echo esc_html( $this->get_quality() ); ?> %</strong></li>
						</ul>
					</section>

					<section class="opti-pict-card" id="opti-pict-restore-card" <?php echo empty( $restore_ids ) ? 'hidden' : ''; ?>>
						<h2><?php esc_html_e( 'Revenir aux originaux', 'opti-pict' ); ?></h2>
						<p><?php esc_html_e( 'Les fichiers d’origine sont conservés. Vous pouvez rétablir les références précédentes à tout moment.', 'opti-pict' ); ?></p>
						<button type="button" class="button" id="opti-pict-restore" data-ids="<?php echo esc_attr( wp_json_encode( $restore_ids ) ); ?>">
							<?php
							printf(
								/* translators: %s: number of images. */
								esc_html( _n( 'Restaurer %s image', 'Restaurer %s images', count( $restore_ids ), 'opti-pict' ) ),
								esc_html( number_format_i18n( count( $restore_ids ) ) )
							);
							?>
						</button>
					</section>

					<?php if ( is_array( $last_report ) && ! empty( $last_report['saved'] ) ) : ?>
						<section class="opti-pict-card opti-pict-last">
							<h2><?php esc_html_e( 'Dernier gain', 'opti-pict' ); ?></h2>
							<strong><?php echo esc_html( size_format( (int) $last_report['saved'], 1 ) ); ?></strong>
							<?php if ( ! empty( $last_report['date'] ) ) : ?>
								<p><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), (int) $last_report['date'] ) ); ?></p>
							<?php endif; ?>
						</section>
					<?php endif; ?>
				</aside>
			</main>
		</div>
		<?php
	}

	/**
	 * Return server capabilities.
	 *
	 * @return array<string,bool>
	 */
	private function get_support_status() {
		$uploads = wp_upload_dir();

		return array(
			'webp'             => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'avif'             => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
			'uploads_writable' => empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] ),
		);
	}

	/**
	 * Scan one page of attachments.
	 */
	public function ajax_scan() {
		$format = $this->get_requested_format();
		$this->verify_ajax_request( true, $format );

		// The nonce is verified by verify_ajax_request() immediately above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page  = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Paginated exclusion prevents already processed media from being scanned again.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => self::PROCESSED_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$items       = array();
		$total_bytes = 0;
		$estimated   = 0;

		foreach ( $query->posts as $attachment_id ) {
			$files = $this->get_attachment_files( $attachment_id );
			if ( empty( $files ) ) {
				continue;
			}

			$bytes = 0;
			$after = 0;
			foreach ( $files as $file ) {
				$size   = filesize( $file['path'] );
				$bytes += false === $size ? 0 : (int) $size;
				$ratio  = 'avif' === $format ? ( 'image/png' === $file['mime'] ? 0.45 : 0.58 ) : ( 'image/png' === $file['mime'] ? 0.58 : 0.72 );
				$after += (int) round( ( false === $size ? 0 : $size ) * $ratio );
			}

			if ( $bytes > 0 ) {
				$items[]      = absint( $attachment_id );
				$total_bytes += $bytes;
				$estimated   += $after;
			}
		}

		wp_send_json_success(
			array(
				'ids'            => $items,
				'bytes'          => $total_bytes,
				'estimatedBytes' => $estimated,
				'page'           => $page,
				'totalPages'     => (int) $query->max_num_pages,
				'totalPosts'     => (int) $query->found_posts,
				'format'         => $format,
			)
		);
	}

	/**
	 * Optimize one attachment.
	 */
	public function ajax_optimize() {
		$format = $this->get_requested_format();
		$this->verify_ajax_request( true, $format );

		// The nonce is verified by verify_ajax_request() immediately above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attachment_id = isset( $_POST['attachmentId'] ) ? absint( wp_unslash( $_POST['attachmentId'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Image introuvable.', 'opti-pict' ) ), 404 );
		}

		$result = $this->optimize_attachment( $attachment_id, $format );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'      => $result->get_error_message(),
					'attachmentId' => $attachment_id,
				),
				422
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Restore one attachment.
	 */
	public function ajax_restore() {
		$this->verify_ajax_request( false );

		// The nonce is verified by verify_ajax_request() immediately above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attachment_id = isset( $_POST['attachmentId'] ) ? absint( wp_unslash( $_POST['attachmentId'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Image introuvable.', 'opti-pict' ) ), 404 );
		}

		$result = $this->restore_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'      => $result->get_error_message(),
					'attachmentId' => $attachment_id,
				),
				422
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Save the aggregate report shown after an optimization run.
	 */
	public function ajax_save_report() {
		$this->verify_ajax_request( false );

		// The nonce is verified by verify_ajax_request() immediately above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$report = array(
			'before' => isset( $_POST['before'] ) ? absint( wp_unslash( $_POST['before'] ) ) : 0,
			'after'  => isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0,
			'saved'  => isset( $_POST['saved'] ) ? absint( wp_unslash( $_POST['saved'] ) ) : 0,
			'images' => isset( $_POST['images'] ) ? absint( wp_unslash( $_POST['images'] ) ) : 0,
			'date'   => time(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( self::LAST_REPORT_OPTION, $report, false );
		wp_send_json_success();
	}

	/**
	 * Check permissions, nonce and server support.
	 *
	 * @param bool   $require_support Whether image encoding and writable uploads are required.
	 * @param string $format          Requested output format.
	 */
	private function verify_ajax_request( $require_support = true, $format = 'webp' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Action non autorisée.', 'opti-pict' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( $require_support ) {
			$support = $this->get_support_status();
			if ( empty( $support[ $format ] ) || ! $support['uploads_writable'] ) {
				wp_send_json_error( array( 'message' => __( 'Le serveur ne peut pas créer le format demandé dans le dossier des téléversements.', 'opti-pict' ) ), 500 );
			}
		}
	}

	/**
	 * Read and strictly validate the requested output format.
	 *
	 * @return string
	 */
	private function get_requested_format() {
		// The nonce is checked by the calling AJAX method before any action is performed.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'webp';
		return in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'webp';
	}

	/**
	 * Optimize an attachment and its registered sizes.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Output format.
	 * @return array<string,mixed>|WP_Error
	 */
	private function optimize_attachment( $attachment_id, $format ) {
		$lock = $this->acquire_attachment_lock( $attachment_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return $this->optimize_attachment_locked( $attachment_id, $format );
		} finally {
			$this->release_attachment_lock( $attachment_id );
		}
	}

	/**
	 * Optimize an attachment while its per-attachment lock is held.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Output format.
	 * @return array<string,mixed>|WP_Error
	 */
	private function optimize_attachment_locked( $attachment_id, $format ) {
		if ( get_post_meta( $attachment_id, self::PROCESSED_META, true ) ) {
			return array(
				'attachmentId' => $attachment_id,
				'before'       => 0,
				'after'        => 0,
				'saved'        => 0,
				'converted'    => 0,
				'skipped'      => 1,
			);
		}

		$files = $this->get_attachment_files( $attachment_id );
		if ( empty( $files ) ) {
			return new WP_Error( 'opti_pict_no_file', __( 'Aucun fichier JPEG ou PNG exploitable pour cette image.', 'opti-pict' ) );
		}

		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$backup        = array(
			'attached_file' => $attached_file,
			'metadata'      => $metadata,
			'post_mime'     => get_post_mime_type( $attachment_id ),
			'generated'     => array(),
			'created_at'    => time(),
		);
		$new_metadata  = is_array( $metadata ) ? $metadata : array();
		$before        = 0;
		$after         = 0;
		$converted     = 0;
		$skipped       = 0;
		$full_output   = '';
		$output_mime   = 'avif' === $format ? 'image/avif' : 'image/webp';

		foreach ( $files as $file ) {
			$source_size = filesize( $file['path'] );
			if ( false === $source_size || 0 === $source_size ) {
				++$skipped;
				continue;
			}

			$before     += (int) $source_size;
			$destination = $this->get_destination_path( $file['path'], $format );
			$editor      = wp_get_image_editor( $file['path'] );

			if ( is_wp_error( $editor ) ) {
				++$skipped;
				$after += (int) $source_size;
				continue;
			}

			$editor->set_quality( $this->get_quality() );
			$saved_file = $editor->save( $destination, $output_mime );
			if ( is_wp_error( $saved_file ) || empty( $saved_file['path'] ) || ! $this->is_safe_generated_file( $saved_file['path'], $output_mime ) ) {
				++$skipped;
				$after += (int) $source_size;
				continue;
			}

			$relative_output = $this->absolute_to_relative_upload_path( $saved_file['path'] );
			if ( false === $relative_output ) {
				wp_delete_file( $saved_file['path'] );
				++$skipped;
				$after += (int) $source_size;
				continue;
			}

			$output_size = filesize( $saved_file['path'] );
			if ( false === $output_size || $output_size >= $source_size ) {
				wp_delete_file( $saved_file['path'] );
				++$skipped;
				$after += (int) $source_size;
				continue;
			}

			++$converted;
			$after                += (int) $output_size;
			$backup['generated'][] = $relative_output;

			if ( 'full' === $file['key'] ) {
				$full_output              = $saved_file['path'];
				$new_metadata['file']     = $relative_output;
				$new_metadata['filesize'] = (int) $output_size;
			} elseif ( isset( $new_metadata['sizes'][ $file['key'] ] ) ) {
				$new_metadata['sizes'][ $file['key'] ]['file']      = wp_basename( $saved_file['path'] );
				$new_metadata['sizes'][ $file['key'] ]['mime-type'] = $output_mime;
				$new_metadata['sizes'][ $file['key'] ]['filesize']  = (int) $output_size;
			}
		}

		if ( 0 === $converted ) {
			update_post_meta( $attachment_id, self::PROCESSED_META, time() );
			return array(
				'attachmentId' => $attachment_id,
				'before'       => $before,
				'after'        => $before,
				'saved'        => 0,
				'converted'    => 0,
				'skipped'      => $skipped,
			);
		}

		$backup['before'] = $before;
		$backup['after']  = $after;
		$backup['saved']  = max( 0, $before - $after );
		$backup['format'] = $format;

		if ( ! add_post_meta( $attachment_id, self::BACKUP_META, $backup, true ) ) {
			foreach ( $backup['generated'] as $relative_path ) {
				$this->delete_generated_file( $relative_path );
			}
			return new WP_Error( 'opti_pict_backup_failed', __( 'La sauvegarde de sécurité n’a pas pu être créée. Aucun changement n’a été appliqué.', 'opti-pict' ) );
		}

		$update_failed = false;
		if ( $full_output ) {
			update_attached_file( $attachment_id, $full_output );

			$post_result = wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $output_mime,
				),
				true
			);
			if ( is_wp_error( $post_result ) ) {
				$update_failed = true;
			}
		}

		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		$current_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( $current_metadata != $new_metadata ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Metadata filters may normalize scalar types.
			$update_failed = true;
		}
		if ( $full_output && get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $new_metadata['file'] ) {
			$update_failed = true;
		}
		if ( $full_output && $output_mime !== get_post_mime_type( $attachment_id ) ) {
			$update_failed = true;
		}

		if ( $update_failed ) {
			$restore_result = $this->restore_attachment_locked( $attachment_id );
			if ( is_wp_error( $restore_result ) ) {
				return new WP_Error( 'opti_pict_update_and_restore_failed', __( 'WordPress n’a pas pu confirmer les nouvelles références ni la restauration automatique. Les fichiers ont été conservés : utilisez l’action de restauration avant toute nouvelle tentative.', 'opti-pict' ) );
			}
			return new WP_Error( 'opti_pict_update_failed', __( 'WordPress n’a pas pu enregistrer les nouvelles références. Les originaux ont été rétablis.', 'opti-pict' ) );
		}

		update_post_meta( $attachment_id, self::PROCESSED_META, time() );
		clean_post_cache( $attachment_id );

		return array(
			'attachmentId' => $attachment_id,
			'before'       => $before,
			'after'        => $after,
			'saved'        => max( 0, $before - $after ),
			'converted'    => $converted,
			'skipped'      => $skipped,
			'format'       => $format,
			'item'         => $this->get_restore_item( $attachment_id ),
		);
	}

	/**
	 * Restore original metadata and delete only files generated by the plugin.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,int>|WP_Error
	 */
	private function restore_attachment( $attachment_id ) {
		$lock = $this->acquire_attachment_lock( $attachment_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return $this->restore_attachment_locked( $attachment_id );
		} finally {
			$this->release_attachment_lock( $attachment_id );
		}
	}

	/**
	 * Restore an attachment while its per-attachment lock is held.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,int>|WP_Error
	 */
	private function restore_attachment_locked( $attachment_id ) {
		$backup = get_post_meta( $attachment_id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) || empty( $backup['attached_file'] ) || ! array_key_exists( 'metadata', $backup ) ) {
			return new WP_Error( 'opti_pict_no_backup', __( 'Aucune sauvegarde n’est disponible pour cette image.', 'opti-pict' ) );
		}

		$original = $this->relative_to_absolute_upload_path( $backup['attached_file'] );
		if ( ! $original || ! $this->is_safe_source_file( $original ) ) {
			return new WP_Error( 'opti_pict_original_missing', __( 'Le fichier d’origine est introuvable. La restauration a été interrompue.', 'opti-pict' ) );
		}

		update_attached_file( $attachment_id, $original );
		if ( is_array( $backup['metadata'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $backup['metadata'] );
		} else {
			delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
		}
		$post_result = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => sanitize_mime_type( $backup['post_mime'] ),
			),
			true
		);

		$current_metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata_matches = is_array( $backup['metadata'] )
			? $current_metadata == $backup['metadata'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Metadata filters may normalize scalar types.
			: empty( $current_metadata );

		if (
			is_wp_error( $post_result ) ||
			get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $backup['attached_file'] ||
			! $metadata_matches ||
			sanitize_mime_type( $backup['post_mime'] ) !== get_post_mime_type( $attachment_id )
		) {
			return new WP_Error( 'opti_pict_restore_unconfirmed', __( 'WordPress n’a pas pu confirmer la restauration. Aucun fichier optimisé n’a été supprimé.', 'opti-pict' ) );
		}

		$deleted = 0;
		foreach ( (array) ( $backup['generated'] ?? array() ) as $relative_path ) {
			if ( $this->delete_generated_file( $relative_path ) ) {
				++$deleted;
			}
		}

		delete_post_meta( $attachment_id, self::BACKUP_META );
		delete_post_meta( $attachment_id, self::PROCESSED_META );
		clean_post_cache( $attachment_id );

		return array(
			'attachmentId' => $attachment_id,
			'deleted'      => $deleted,
		);
	}

	/**
	 * Acquire an atomic, expiring lock for a media item.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	private function acquire_attachment_lock( $attachment_id ) {
		$lock_key = 'opti_pict_lock_' . absint( $attachment_id );
		if ( add_option( $lock_key, time(), '', false ) ) {
			return true;
		}

		$locked_at = absint( get_option( $lock_key, 0 ) );
		if ( $locked_at && $locked_at < time() - ( 10 * MINUTE_IN_SECONDS ) ) {
			delete_option( $lock_key );
			if ( add_option( $lock_key, time(), '', false ) ) {
				return true;
			}
		}

		return new WP_Error( 'opti_pict_locked', __( 'Cette image est déjà en cours de traitement. Réessayez dans un instant.', 'opti-pict' ) );
	}

	/**
	 * Release a media item lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function release_attachment_lock( $attachment_id ) {
		delete_option( 'opti_pict_lock_' . absint( $attachment_id ) );
	}

	/**
	 * Get source files for a media attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,array<string,string>>
	 */
	private function get_attachment_files( $attachment_id ) {
		$attached = get_attached_file( $attachment_id );
		if ( ! $attached || ! $this->is_safe_source_file( $attached ) ) {
			return array();
		}

		$mime  = wp_get_image_mime( $attached );
		$files = array(
			array(
				'key'  => 'full',
				'path' => $attached,
				'mime' => $mime,
			),
		);
		$seen  = array( wp_normalize_path( $attached ) => true );
		$meta  = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $files;
		}

		$directory = trailingslashit( dirname( $attached ) );
		foreach ( $meta['sizes'] as $key => $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			$path       = $directory . wp_basename( $size['file'] );
			$normalized = wp_normalize_path( $path );
			if ( isset( $seen[ $normalized ] ) || ! $this->is_safe_source_file( $path ) ) {
				continue;
			}

			$size_mime = wp_get_image_mime( $path );
			if ( ! in_array( $size_mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}

			$seen[ $normalized ] = true;
			$files[]             = array(
				'key'  => sanitize_key( $key ),
				'path' => $path,
				'mime' => $size_mime,
			);
		}

		return $files;
	}

	/**
	 * Ensure a source is a local JPEG/PNG inside uploads.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_safe_source_file( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return false;
		}

		$real_path = realpath( $path );
		$uploads   = wp_upload_dir();
		$base_path = realpath( $uploads['basedir'] );
		if ( false === $real_path || false === $base_path || 0 !== strpos( wp_normalize_path( $real_path ), trailingslashit( wp_normalize_path( $base_path ) ) ) ) {
			return false;
		}

		return in_array( wp_get_image_mime( $real_path ), array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * Build a deterministic optimized filename.
	 *
	 * @param string $source Source path.
	 * @param string $format Output extension.
	 * @return string
	 */
	private function get_destination_path( $source, $format ) {
		$info      = pathinfo( $source );
		$extension = 'avif' === $format ? 'avif' : 'webp';
		$filename  = sanitize_file_name( $info['filename'] . '-opti-pict.' . $extension );
		$unique    = wp_unique_filename( $info['dirname'], $filename );
		return trailingslashit( $info['dirname'] ) . $unique;
	}

	/**
	 * Convert an absolute uploads path to a relative one.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	private function absolute_to_relative_upload_path( $path ) {
		$uploads = wp_upload_dir();
		$real    = realpath( $path );
		$base    = realpath( $uploads['basedir'] );
		if ( false === $real || false === $base ) {
			return false;
		}

		$real = wp_normalize_path( $real );
		$base = trailingslashit( wp_normalize_path( $base ) );
		return 0 === strpos( $real, $base ) ? ltrim( substr( $real, strlen( $base ) ), '/' ) : false;
	}

	/**
	 * Resolve and validate a relative uploads path.
	 *
	 * @param string $relative_path Relative path.
	 * @return string|false
	 */
	private function relative_to_absolute_upload_path( $relative_path ) {
		if ( ! is_string( $relative_path ) || '' === $relative_path || false !== strpos( $relative_path, '..' ) || path_is_absolute( $relative_path ) ) {
			return false;
		}

		$uploads = wp_upload_dir();
		$path    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $relative_path, '/\\' ) );
		$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

		return 0 === strpos( $path, $base ) ? $path : false;
	}

	/**
	 * Delete a generated optimized image after strict path and filename validation.
	 *
	 * @param string $relative_path Relative uploads path.
	 * @return bool
	 */
	private function delete_generated_file( $relative_path ) {
		$path = $this->relative_to_absolute_upload_path( $relative_path );
		if ( ! $path || ! preg_match( '/-opti-pict(?:-\d+)?\.(?:webp|avif)$/i', $path ) || ! $this->is_safe_generated_file( $path ) ) {
			return false;
		}

		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return false;
		}

		wp_delete_file( $real_path );
		return ! file_exists( $real_path );
	}

	/**
	 * Ensure an output is a regular optimized file inside uploads.
	 *
	 * @param string $path          Absolute path.
	 * @param string $expected_mime Optional expected MIME type.
	 * @return bool
	 */
	private function is_safe_generated_file( $path, $expected_mime = '' ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return false;
		}

		$real_path = realpath( $path );
		$uploads   = wp_upload_dir();
		$base_path = realpath( $uploads['basedir'] );
		if ( false === $real_path || false === $base_path || 0 !== strpos( wp_normalize_path( $real_path ), trailingslashit( wp_normalize_path( $base_path ) ) ) ) {
			return false;
		}

		$mime = wp_get_image_mime( $real_path );
		if ( $expected_mime ) {
			return $expected_mime === $mime;
		}

		return in_array( $mime, array( 'image/webp', 'image/avif' ), true );
	}

	/**
	 * Get restorable attachment IDs.
	 *
	 * @return int[]
	 */
	private function get_restore_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// Only attachments with a plugin backup can be safely restored.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => self::BACKUP_META,
			)
		);
	}

	/**
	 * Build display records for optimized attachments.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_restore_items( $attachment_ids ) {
		$items = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$item = $this->get_restore_item( $attachment_id );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Return the gain and identity of one optimized image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>|false
	 */
	private function get_restore_item( $attachment_id ) {
		$backup = get_post_meta( $attachment_id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) ) {
			return false;
		}

		$before = absint( $backup['before'] ?? 0 );
		$after  = absint( $backup['after'] ?? 0 );
		$saved  = absint( $backup['saved'] ?? max( 0, $before - $after ) );
		return array(
			'id'        => absint( $attachment_id ),
			'title'     => get_the_title( $attachment_id ),
			'thumbnail' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'filename'  => wp_basename( (string) get_attached_file( $attachment_id ) ),
			'before'    => $before,
			'after'     => $after,
			'saved'     => $saved,
			'percent'   => $before > 0 ? round( ( $saved / $before ) * 100, 1 ) : 0,
			'format'    => strtoupper( sanitize_key( $backup['format'] ?? 'webp' ) ),
		);
	}

	/**
	 * Render one restorable image row.
	 *
	 * @param array<string,mixed> $item Image record.
	 */
	private function render_restore_item( $item ) {
		?>
		<article class="opti-pict-optimized-item" data-attachment-id="<?php echo esc_attr( $item['id'] ); ?>">
			<?php if ( $item['thumbnail'] ) : ?><img src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt=""><?php endif; ?>
			<div class="opti-pict-optimized-item__identity">
				<strong><?php echo esc_html( $item['title'] ?: $item['filename'] ); ?></strong>
				<span><?php echo esc_html( $item['filename'] ); ?> · <?php echo esc_html( $item['format'] ); ?></span>
			</div>
			<div class="opti-pict-optimized-item__gain">
				<strong><?php echo $item['saved'] ? esc_html( size_format( $item['saved'], 1 ) . ' (' . number_format_i18n( $item['percent'], 1 ) . ' %)' ) : esc_html__( 'Gain non mesuré', 'opti-pict' ); ?></strong>
				<span><?php echo $item['before'] && $item['after'] ? esc_html( size_format( $item['before'], 1 ) . ' → ' . size_format( $item['after'], 1 ) ) : esc_html__( 'Optimisée avec une version antérieure', 'opti-pict' ); ?></span>
			</div>
			<button type="button" class="button opti-pict-restore-one"><?php esc_html_e( 'Restaurer', 'opti-pict' ); ?></button>
			<p class="opti-pict-optimized-item__status screen-reader-text" role="status" aria-live="polite"></p>
		</article>
		<?php
	}

	/**
	 * Filterable output quality.
	 *
	 * @return int
	 */
	private function get_quality() {
		/**
		 * Filters the image quality used by Opti Pict.
		 *
		 * @param int $quality Quality from 1 to 100.
		 */
		return min( 100, max( 1, (int) apply_filters( 'opti_pict_webp_quality', 82 ) ) );
	}
}
