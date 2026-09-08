<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="plathix-support-footer">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=plathix-system-info' ) ); ?>"><?php esc_html_e( 'System Info', 'plathix' ); ?></a>
	<a href="<?php echo esc_url( \Plathix\Admin\ExternalLink::marketing( '/docs/', 'support_footer_docs' ) ); ?>" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'Docs', 'plathix' ); ?></a>
	<a href="<?php echo esc_url( \Plathix\Admin\ExternalLink::marketing( '/support/', 'support_footer_support' ) ); ?>" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'Support', 'plathix' ); ?></a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=plathix-pro' ) ); ?>" class="plathix-support-footer__pro-teaser"><?php esc_html_e( 'More power in PRO', 'plathix' ); ?></a>
</div>
