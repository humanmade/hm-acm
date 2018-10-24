<?php

namespace HM\ACM\Admin;

use function HM\ACM\get_suggested_domains;
use function HM\ACM\has_certificate;
use function HM\ACM\create_certificate;
use Exception;
use function HM\ACM\has_verified_certificate;
use function HM\ACM\get_certificate;
use function HM\ACM\refresh_certificate;
use function HM\ACM\has_cloudfront_distribution;
use function HM\ACM\create_cloudfront_distribution;
use function HM\ACM\get_cloudfront_distribution;
use function HM\ACM\has_completed_cloudfront_distribution;
use function HM\ACM\refresh_cloudfront_distribution;
use function HM\ACM\update_cloudfront_distribution_config;
use function HM\ACM\unlink_certificate;
use function HM\ACM\unlink_cloudfront_distribution;

function bootstrap() {
	add_submenu_page( 'tools.php', __( 'HTTPS Certificate', 'hm-acm' ), __( 'HTTPS Certificate', 'hm-acm' ), 'manage_options', 'hm-acm', __NAMESPACE__ . '\\admin_page' );

	if ( ! empty( $_POST['hm-acm-action'] ) && $_POST['hm-acm-action'] === 'create-certificate' ) { // @codingStandardsIgnoreLine
		on_create_certificate();
	}

	if ( ! empty( $_POST['hm-acm-action'] ) && $_POST['hm-acm-action'] === 'refresh-certificate' ) { // @codingStandardsIgnoreLine
		on_refresh_certificate();
	}

	if ( ! empty( $_POST['hm-acm-action'] ) && $_POST['hm-acm-action'] === 'create-cloudfront-distribution' ) { // @codingStandardsIgnoreLine
		on_create_cloudfront_distribution();
	}

	if ( ! empty( $_POST['hm-acm-action'] ) && $_POST['hm-acm-action'] === 'refresh-cloudfront-distribution' ) { // @codingStandardsIgnoreLine
		on_refresh_cloudfront_distribution();
	}

	if ( ! empty( $_GET['hm-acm-action'] ) && $_GET['hm-acm-action'] === 'update-cloudfront-distribution-config' ) {
		on_update_cloudfront_distribution_config();
	}

	if ( ! empty( $_GET['hm-acm-action'] ) && $_GET['hm-acm-action'] === 'unlink-certificate' ) {
		on_unlink_certificate();
	}

	if ( ! empty( $_GET['hm-acm-action'] ) && $_GET['hm-acm-action'] === 'unlink-cloudfront-distribution' ) {
		on_unlink_cloudfront_distribution();
	}
}

function on_create_certificate() {
	check_admin_referer( 'hm-acm-create-certificate' );
	$domains = array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['domains'] ) ) ) );
	try {
		$certificate = create_certificate( $domains );
	} catch ( Exception $e ) {
		wp_die( $e->getMessage() );
	}

	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	exit;
}

function on_refresh_certificate() {
	check_admin_referer( 'hm-acm-refresh-certificate' );
	refresh_certificate();
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	exit;
}

function on_unlink_certificate() {
	check_admin_referer( 'hm-acm-unlink-certificate' );
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	unlink_certificate();
	exit;
}

function on_create_cloudfront_distribution() {
	check_admin_referer( 'hm-acm-create-cloudfront-distribution' );

	try {
		$certificate = create_cloudfront_distribution();
	} catch ( Exception $e ) {
		wp_die( $e->getMessage() );
	}

	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	exit;
}

function on_refresh_cloudfront_distribution() {
	check_admin_referer( 'hm-acm-refresh-cloudfront-distribution' );
	refresh_cloudfront_distribution();
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	exit;
}

function on_update_cloudfront_distribution_config() {
	check_admin_referer( 'hm-acm-update-cloudfront-distribution-config' );
	try {
		update_cloudfront_distribution_config();
	} catch ( Exception $e ) {
		wp_die( $e->getMessage() );
	}
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	exit;

}

function on_unlink_cloudfront_distribution() {
	check_admin_referer( 'hm-acm-unlink-cloudfront-sitribution' );
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	unlink_cloudfront_distribution();
	exit;
}

function admin_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'HTTPS Certificate', 'hm-acm' ) ?></h1>
		<?php if ( has_certificate() ) :
			$certificate = get_certificate();
			?>
			<h4>HTTPS Certificate: <?php echo esc_html( implode( ', ', $certificate['SubjectAlternativeNames'] ) ) ?> (<?php echo esc_html( $certificate['Status'] ) ?>)</h4>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'unlink-certificate' ), 'hm-acm-unlink-certificate' ) ) ?>" class="button button-secondary">Unlink</a>
		<?php endif ?>
		<?php if ( has_cloudfront_distribution() ) :
			$distribution = get_cloudfront_distribution();
			?>
			<h4>CDN Distribution: <?php echo esc_html( $distribution['Id'] ) ?></h4>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'unlink-cloudfront-distribution' ), 'hm-acm-unlink-cloudfront-distribution' ) ) ?>" class="button button-secondary">Unlink</a>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'update-cloudfront-distribution-config' ), 'hm-acm-update-cloudfront-distribution-config' ) ) ?>" class="button button-secondary">Update Config</a>
			<p>Please update the following DNS records to your domain(s) to activate HTTPS. If you want to support HTTPS on the root of your domain, you need to use AWS Route 53 with an "ALIAS" recording point the cloudfront domain listed below.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Domain', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Record Type', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Value', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Status', 'hm-acm' ) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $distribution['DistributionConfig']['Aliases']['Items'] as $domain ) : ?>
						<tr>
							<td><?php echo esc_html( $domain ) ?></td>
							<td>CNAME</td>
							<td><?php echo esc_html( $distribution['DomainName'] ) ?></td>
							<td><?php echo dns_get_record( $domain, DNS_CNAME )['target'] === $distribution['DomainName'] ? 'Varified' : 'Unknown' ?></td>
						</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>
		<?php if ( ! has_certificate() ) : ?>
			<form method="post">
				<p>
					<label><?php _e( 'HTTPS Domains', 'hm-acm' ) ?></label><br />
					<input type="text" class="widefat" value="<?php echo esc_attr( implode( ', ', get_suggested_domains() ) ) ?>" name="domains" />
				</p>
				<p class="description">
					<?php _e( 'Seperate multiple domains with a comma.' ) ?>
				</p>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Request Certificate', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-create-certificate' ) ?>
				<input type="hidden" name="hm-acm-action" value="create-certificate" />
			</form>
		<?php elseif ( ! has_verified_certificate() ) : // @codingStandardsIgnoreLine
			$certificate = get_certificate();
			?>
			<p><?php printf( esc_html__( 'Certificate status: %s', 'hm-acm' ), '<strong>' . esc_html( $certificate['Status'] ) . '</strong>' ) ?>.</p>
			<p>Please add the following DNS records to your domain(s) to validate.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Domain', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Record Type', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Value', 'hm-acm' ) ?></th>
						<th><?php esc_html_e( 'Status', 'hm-acm' ) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $certificate['DomainValidationOptions'] as $domain ) : ?>
						<tr>
							<td><?php echo esc_html( $domain['ResourceRecord']['Name'] ) ?></td>
							<td><?php echo esc_html( $domain['ResourceRecord']['Type'] ) ?></td>
							<td><?php echo esc_html( $domain['ResourceRecord']['Value'] ) ?></td>
							<td><?php echo esc_html( $domain['ValidationStatus'] ) ?></td>
						</tr>
					<?php endforeach ?>
				</tbody>
			</table>
			<form method="post">
				<p class="submit">
					<input class="button-primary" type="submit" value="<?php esc_attr_e( 'Refresh', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-refresh-certificate' ) ?>
				<input type="hidden" name="hm-acm-action" value="refresh-certificate" />
			</form>
		<?php elseif ( ! has_cloudfront_distribution() ) : ?>
			<form method="post">
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Create CDN', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-create-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="create-cloudfront-distribution" />
			</form>
		<?php elseif ( ! has_completed_cloudfront_distribution() ) : ?>
			<p><?php printf( esc_html__( 'CDN Status: %s. The CDN take take up to 45 minutes to update.', 'hm-acm' ), esc_html( get_cloudfront_distribution()['Status'] ) ) ?></p>
			<form method="post">
				<p class="submit">
					<input class="button-primary" type="submit" value="<?php esc_attr_e( 'Refresh', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-refresh-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="refresh-cloudfront-distribution" />
			</form>
		<?php endif ?>

	</div>
	<?php
}
