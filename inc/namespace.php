<?php

namespace HM\ACM;

use Exception;

function has_certificate() : bool {
	return (bool) get_option( 'hm-acm-certificate' );
}

function has_verified_certificate() {
	return get_certificate()['Status'] === 'ISSUED';
}

function get_certificate() : array {
	return get_option( 'hm-acm-certificate' );
}

function refresh_certificate() {
	try {
		$certificate = get_aws_acm_client()->describeCertificate([
			'CertificateArn' => get_certificate()['CertificateArn'],
		])['Certificate'];
		update_option( 'hm-acm-certificate', $certificate );
	} catch ( Exception $e ) {
		delete_option( 'hm-acm-certificate' );
	}
}

function get_suggested_domains() : array {
	$hostname = wp_parse_url( site_url(), PHP_URL_HOST );
	$secondary = strpos( $hostname, 'www.' ) === 0 ? substr( $hostname, 4 ) : 'www.' . $hostname;
	return [ $hostname, $secondary ];
}

/**
 * Get a new ACM certificate.
 *
 * @param array $domains
 * @return string
 */
function create_certificate( array $domains ) : array {
	$primary = $domains[0];
	$alternate_domains = count( $domains ) > 1 ? array_slice( $domains, 1 ) : [];
	$params = [
		'DomainName' => $primary,
		'Options' => [
			'CertificateTransparencyLoggingPreference' => 'ENABLED',
		],
		'ValidationMethod' => 'DNS',
	];

	if ( $alternate_domains ) {
		$params['SubjectAlternativeNames'] = $alternate_domains;
	}
	$result = get_aws_acm_client()->requestCertificate( $params );

	$arn = $result['CertificateArn'];

	sleep( 3 ); // What a great hack.
	$certificate = get_aws_acm_client()->describeCertificate([
		'CertificateArn' => $arn,
	])['Certificate'];

	update_option( 'hm-acm-certificate', $certificate );
	return $certificate;
}

function has_cloudfront_distribution() {
	return (bool) get_option( 'hm-cloudfront-distribution' );
}

function has_completed_cloudfront_distribution() {
	return get_cloudfront_distribution()['Status'] === 'Deployed';
}

function get_cloudfront_distribution() {
	return get_option( 'hm-cloudfront-distribution' );
}


function refresh_cloudfront_distribution() {
	try {
		$cloudfront_distribution = get_aws_cloudfront_client()->getDistribution([
			'Id' => get_cloudfront_distribution()['Id'],
		])['Distribution'];
		update_option( 'hm-cloudfront-distribution', $cloudfront_distribution );
	} catch ( Exception $e ) {

	}
}

function create_cloudfront_distribution( string $upstream_domain ) {
	$result = get_aws_cloudfront_client()->createDistribution( get_cloudfront_distribution_config( $upstream_domain ) );
	update_option( 'hm-cloudfront-distribution', $result['Distribution'] );
}

function get_cloudfront_distribution_config( string $upstream_domain ) : array {
	$certificate = get_certificate();
	$domains = array_unique( array_merge( [ $certificate['DomainName'] ], $certificate['SubjectAlternativeNames'] ) );
	return [
		'DistributionConfig' => [
			'CallerReference' => site_url(),
			'Aliases' => [
				'Items' => $domains,
				'Quantity' => count( $domains ),
			],
			'Comment' => 'Distribution for ' . site_url(),
			'DefaultCacheBehavior' => [
				'AllowedMethods' => [
					'CachedMethods' => [
						'Items' => [ 'GET', 'HEAD' ],
						'Quantity' => 2,
					],
					'Items' => [ 'GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'POST', 'DELETE' ],
					'Quantity' => 7,
				],
				'Compress' => true,
				'DefaultTTL' => 0,
				'ForwardedValues' => [
					'Cookies' => [
						'Forward' => 'whitelist',
						'WhitelistedNames' => [
							'Items' => [ 'wp_*', 'wordpress_*', 'hm_*' ],
							'Quantity' => 3,
						],
					],
					'Headers' => [
						'Items' => [ 'Authorization' ],
						'Quantity' => 1,
					],
					'QueryString' => true,
				],
				'MaxTTL' => 3600 * 24 * 265,
				'MinTTL' => 0,
				'TargetOriginId' => 'upstream',
				'ViewerProtocolPolicy' => 'redirect-to-https',
				'TrustedSigners' => [
					'Enabled' => false,
					'Quantity' => 0,
				],
			],
			'Enabled' => true,
			'HttpVersion' => 'http2',
			'IsIPV6Enabled' => true,
			'Origins' => [
				'Items' => [
					[
						'CustomOriginConfig' => [
							'HTTPPort' => 80,
							'HTTPSPort' => 443,
							'OriginProtocolPolicy' => 'https-only',
							'OriginSslProtocols' => [
								'Items' => [ 'SSLv3', 'TLSv1', 'TLSv1.1', 'TLSv1.2' ],
								'Quantity' => 4,
							],
						],
						'DomainName' => $upstream_domain,
						'Id' => 'upstream',
						'CustomHeaders' => [
							'Items' => [
								[
									'HeaderName' => 'X-Forwarded-Host',
									'HeaderValue' => $domains[0],
								],
							],
							'Quantity' => 1,
						],
					],
				],
				'Quantity' => 1,
			],
			'PriceClass' => 'PriceClass_All',
			'ViewerCertificate' => [
				'ACMCertificateArn' => $certificate['CertificateArn'],
				'CertificateSource' => 'acm',
				'CloudFrontDefaultCertificate' => false,
				'MinimumProtocolVersion' => 'TLSv1',
				'SSLSupportMethod' => 'sni-only',
			],
		],
	];
}
function get_aws_acm_client() {
	return get_aws_sdk()->createAcm();
}

function get_aws_cloudfront_client() {
	return get_aws_sdk()->createCloudFront();
}

function get_aws_sdk() {
	static $sdk;
	if ( $sdk ) {
		return $sdk;
	}

	$params = [
		'region'   => 'us-east-1',
		'version'  => 'latest',
	];

	$params['credentials'] = [
		'key'    => HM_ACM_AWS_KEY,
		'secret' => HM_ACM_AWS_SECRET,
	];
	$sdk = new \Aws\Sdk( $params );
	return $sdk;
}
