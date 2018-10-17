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
}

function on_create_cloudfront_distribution() {
	check_admin_referer( 'hm-acm-create-cloudfront-distribution' );
	$origin_domain = trim( sanitize_text_field( wp_unslash( $_POST['origin'] ) ) );

	try {
		$certificate = create_cloudfront_distribution( $origin_domain );
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
}

function admin_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'HTTPS Certificate', 'hm-acm' ) ?></h2>
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
				<p>
					<label><?php _e( 'CDN Origin Domain', 'hm-acm' ) ?></label><br />
					<input type="text" class="widefat" value="<?php echo esc_attr( is_multisite() ? NETWORK_PRIMARY_DOMAIN : '' ) ?>" name="origin" />
				</p>
				<p class="description">
					<?php _e( 'A domain name that points to your origin server.' ) ?>
				</p>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Create CDN', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-create-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="create-cloudfront-distribution" />
			</form>
		<?php elseif ( ! has_completed_cloudfront_distribution() ) : ?>
			<p><?php printf( esc_html__( 'CDN Status: %s', 'hm-acm' ), esc_html( get_cloudfront_distribution()['Status'] ) ) ?>.</p>
			<form method="post">
				<p class="submit">
					<input class="button-primary" type="submit" value="<?php esc_attr_e( 'Refresh', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-refresh-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="refresh-cloudfront-distribution" />
			</form>
		<?php elseif ( has_cloudfront_distribution() ) : // @codingStandardsIgnoreLine
			$cloudfront_distribution = get_cloudfront_distribution();
			?>
			<p>Please update the following DNS records to your domain(s) to activate HTTPS.</p>
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
					<?php foreach ( $cloudfront_distribution['DistributionConfig']['Aliases']['Items'] as $domain ) : ?>
						<tr>
							<td><?php echo esc_html( $domain ) ?></td>
							<td>CNAME</td>
							<td><?php echo esc_html( $cloudfront_distribution['DomainName'] ) ?></td>
							<td>UNKNOWN</td>
						</tr>
					<?php endforeach ?>
				</tbody>
			</table>

		<?php endif ?>
	</div>
	<?php
}
