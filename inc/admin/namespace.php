<?php

namespace HM\ACM\Admin;

use function HM\ACM\get_cloudfront_function_arn;
use function HM\ACM\get_cloudfront_origin_request_policy_id;
use function HM\ACM\get_suggested_domains;
use function HM\ACM\has_certificate;
use function HM\ACM\create_certificate;
use Exception;
use function HM\ACM\has_cloudfront_function;
use function HM\ACM\has_cloudfront_origin_request_policy;
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
	check_admin_referer( 'hm-acm-unlink-cloudfront-distribution' );
	wp_safe_redirect( add_query_arg( 'page', 'hm-acm', admin_url( 'tools.php' ) ) );
	unlink_cloudfront_distribution();
	exit;
}

function admin_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'HTTPS Certificate', 'hm-acm' ) ?></h1>
		<?php if ( has_certificate() ) : ?>
			<?php
			$certificate = get_certificate();
			?>
			<h4><?php printf( esc_html__( 'HTTPS Certificate: %1$s (%2$s)', 'hm-acm' ), implode( ', ', $certificate['SubjectAlternativeNames'] ),  $certificate['Status'] ) ?></h4>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'unlink-certificate' ), 'hm-acm-unlink-certificate' ) ) ?>" class="button button-secondary"><?php esc_html_e( 'Unlink', 'hm-acm' ) ?></a>
		<?php endif ?>
		<?php if ( has_cloudfront_function() ) : ?>
			<h4><?php printf( esc_html__( 'CDN Function: %s', 'hm-acm' ), get_cloudfront_function_arn() ) ?></h4>
		<?php endif ?>

		<?php if ( has_cloudfront_origin_request_policy() ) : ?>
			<h4><?php printf( esc_html__( 'CDN Request Policy: %s', 'hm-acm' ), get_cloudfront_origin_request_policy_id() ) ?></h4>
		<?php endif ?>

		<?php if ( has_cloudfront_distribution() ) : ?>
			<?php
			$distribution = get_cloudfront_distribution();
			?>
			<h4><?php printf( esc_html__( 'CDN Distribution: %s', 'hm-acm' ), $distribution['Id'] ) ?></h4>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'unlink-cloudfront-distribution' ), 'hm-acm-unlink-cloudfront-distribution' ) ) ?>" class="button button-secondary"><?php esc_html_e( 'Unlink', 'hm-acm' ) ?></a>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hm-acm-action', 'update-cloudfront-distribution-config' ), 'hm-acm-update-cloudfront-distribution-config' ) ) ?>" class="button button-secondary">Update Config</a>
			<p><?php esc_html_e( 'Please update the following DNS records to your domain(s) to activate HTTPS. If you want to support HTTPS on the root of your domain, you need to use AWS Route 53 with an "ALIAS" recording point the cloudfront domain listed below.' ) ?></p>
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
							<td><?php echo dns_get_record( $domain, DNS_CNAME )['target'] === $distribution['DomainName'] ? 'Verified' : 'Unknown' ?></td>
						</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>
		<?php if ( ! has_certificate() ) : ?>
			<h2><?php esc_html_e( 'Step 1: Request HTTPS Certificate', 'hm-acm' ) ?></h2>
			<p><?php esc_html_e( 'The first step to HTTPS is to request a new HTTPS certificate for the domain(s) that will be used on this website. Just requesting the HTTPS certificate will not change anything on the website, don\'t worry!' ) ?></p>
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
			<h2><?php esc_html_e( 'Step 2: Validate HTTPS Certificate', 'hm-acm' ) ?></h2>
			<p><?php printf( esc_html__( 'Certificate status: %s', 'hm-acm' ), '<strong>' . esc_html( $certificate['Status'] ) . '</strong>' ) ?>.</p>
			<p><?php esc_html_e( 'To verify you control the domains(s) in the HTTPS certificate request, you must add some special DNS records to your domain name. These are added with your domain\'s nameservers (usually whoever you purchased your domain from). Please add the following DNS records to your domain(s) to validate.', 'hm-acm' ) ?></p>
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
							<td><?php echo esc_html( $domain['ResourceRecord']['Name'] ?? '' ) ?></td>
							<td><?php echo esc_html( $domain['ResourceRecord']['Type'] ?? '' ) ?></td>
							<td><?php echo esc_html( $domain['ResourceRecord']['Value'] ?? '' ) ?></td>
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
			<h2><?php esc_html_e( 'Step 3: Create CDN Configuration', 'hm-acm' ) ?></h2>
			<p><?php esc_html_e( 'Now you have a valid HTTPS certificate, we need to create a new CDN configuration that will have the HTTPS certificate attached to it. Any requests made to this CDN will use your HTTPS certificate. To create the CDN, please make sure none of your HTTPS certificate\'s domains are attached to other AWS CloudFront distributions.', 'hm-acm' ) ?></p>
			<form method="post">
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Create CDN', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-create-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="create-cloudfront-distribution" />
			</form>
			<?php do_action( 'hm_acm_below_create_cdn_step' ) ?>
		<?php elseif ( ! has_completed_cloudfront_distribution() ) : ?>
			<h2><?php esc_html_e( 'Apply in progress...', 'hm-acm' ) ?></h2>
			<p><?php printf( esc_html__( 'CDN Status: %s. The CDN take take up to 45 minutes to update. In the meantime your website should largely be available on HTTPS but some connections will take time to be fully updated.', 'hm-acm' ), esc_html( get_cloudfront_distribution()['Status'] ) ) ?></p>
			<form method="post">
				<p class="submit">
					<input class="button-primary" type="submit" value="<?php esc_attr_e( 'Refresh', 'hm-acm' ) ?>" />
				</p>
				<?php wp_nonce_field( 'hm-acm-refresh-cloudfront-distribution' ) ?>
				<input type="hidden" name="hm-acm-action" value="refresh-cloudfront-distribution" />
			</form>
			<?php do_action( 'hm_acm_below_create_cdn_step' ) ?>
		<?php endif ?>

		<?php do_action( 'hm_acm_below_steps' ) ?>
	</div>
	<?php
}
