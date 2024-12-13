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
	/**
	 * Modify suggested domains for the certificate and CloudFront distribution.
	 *
	 * @param array $domains Suggested domains for use with AWS services.
	 */
	return (array) apply_filters( 'hm.acm.suggested-domains', [ $hostname, $secondary ] );
}

/**
 * Get a new ACM certificate.
 *
 * @param array $domains
 * @return array
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

	// Allows enough time for AWS to generate ResourceRecord for provided domains before we call 'describeCertificate' for the certificate at hand.
	sleep( 10 );
	$certificate = get_aws_acm_client()->describeCertificate([
		'CertificateArn' => $arn,
	])['Certificate'];

	update_option( 'hm-acm-certificate', $certificate );
	return $certificate;
}

function unlink_certificate() {
	delete_option( 'hm-acm-certificate' );
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

function create_cloudfront_distribution() {
	if ( ! has_cloudfront_function() ) {
		create_cloudfront_function();
	}

	if ( ! has_cloudfront_origin_request_policy() ) {
		create_cloudfront_origin_request_policy();
	}

	$result = get_aws_cloudfront_client()->createDistribution( [
		'DistributionConfig' => get_cloudfront_distribution_config(),
	] );
	update_option( 'hm-cloudfront-distribution', $result['Distribution'] );
}

function update_cloudfront_distribution_config() {
	$current_distribution = get_aws_cloudfront_client()->getDistribution([
		'Id' => get_cloudfront_distribution()['Id'],
	]);

	if ( ! has_cloudfront_function() ) {
		create_cloudfront_function();
	}

	if ( ! has_cloudfront_origin_request_policy() ) {
		create_cloudfront_origin_request_policy();
	}

	$result = get_aws_cloudfront_client()->updateDistribution( [
		'DistributionConfig' => get_cloudfront_distribution_config(),
		'Id' => get_cloudfront_distribution()['Id'],
		'IfMatch' => $current_distribution['ETag'],
	] );

	update_option( 'hm-cloudfront-distribution', $result['Distribution'] );
}

function unlink_cloudfront_distribution() {
	delete_option( 'hm-cloudfront-distribution' );
	delete_option( 'hm-cloudfront-function' );
	delete_option( 'hm-cloudfront-origin-request-policy' );
}

function get_cloudfront_distribution_config() : array {
	$certificate = get_certificate();
	$domains = array_unique( array_merge( [ $certificate['DomainName'] ], $certificate['SubjectAlternativeNames'] ) );
	$cloudfront_function_arn = get_cloudfront_function_arn();
	$origin_request_policy_id = get_cloudfront_origin_request_policy_id();

	$config = [
		'CallerReference' => site_url(),
		'Aliases' => [
			'Items' => $domains,
			'Quantity' => count( $domains ),
		],
		'DefaultRootObject' => '',
		'Origins' => [
			'Quantity' => 1,
			'Items' => [

				[
					'Id' => 'altis-cloud',
					'DomainName' => HM_ACM_UPSTREAM_DOMAIN,
					'OriginPath' => '',
					'CustomHeaders' => [
						'Quantity' => 0,
					],
					'CustomOriginConfig' => [
						'HTTPPort' => 80,
						'HTTPSPort' => 443,
						'OriginProtocolPolicy' => 'https-only',
						'OriginSslProtocols' => [
							'Quantity' => 1,
							'Items' => [
								'TLSv1.2',
							],
						],
						'OriginReadTimeout' => 60,
						'OriginKeepaliveTimeout' => 5,
					],
					'ConnectionAttempts' => 3,
					'ConnectionTimeout' => 10,
					'OriginShield' => [
						'Enabled' => false,
					],
					'OriginAccessControlId' => '',
				],
			],
		],
		'DefaultCacheBehavior' => [
			'TargetOriginId' => 'altis-cloud',
			'TrustedSigners' => [
				'Enabled' => false,
				'Quantity' => 0,
			],
			'ViewerProtocolPolicy' => 'redirect-to-https',
			'AllowedMethods' => [
				'Quantity' => 7,
				'Items' => [
					'HEAD',
					'DELETE',
					'POST',
					'GET',
					'OPTIONS',
					'PUT',
					'PATCH',
				],
				'CachedMethods' => [
					'Quantity' => 3,
					'Items' => [
						'HEAD',
						'GET',
						'OPTIONS',
					],
				],
			],
			'SmoothStreaming' => false,
			'Compress' => true,
			'FieldLevelEncryptionId' => '',
			'LambdaFunctionAssociations' => [
				'Quantity' => 0,
			],
			"FunctionAssociations" => [
				"Quantity" => 0,
				"Items" => []
			],
			"CachePolicyId" => HM_ACM_CLOUDFRONT_CACHE_POLICY_ID,
			"OriginRequestPolicyId" => $origin_request_policy_id,
		],
		'CacheBehaviors' => [
			'Quantity' => 0,
		],
		'CustomErrorResponses' => [
			'Quantity' => 0,
		],
		'Comment' => '',
		'PriceClass' => 'PriceClass_All',
		'Enabled' => true,
		'ViewerCertificate' => [
			'ACMCertificateArn' => $certificate['CertificateArn'],
			'SSLSupportMethod' => 'sni-only',
			'MinimumProtocolVersion' => 'TLSv1',
		],
		'HttpVersion' => 'http2and3',
		'IsIPV6Enabled' => true,
		'Logging' => [
			'Enabled' => false,
			'IncludeCookies' => false,
			'Prefix' => '',
			'Bucket' => '',
		],
		'WebACLId' => '',
		'Restrictions' => [
			'GeoRestriction' => [
				'Items' => [],
				'Quantity' => 0,
				'RestrictionType' => 'none',
			],
		],
	];

	if ( $cloudfront_function_arn ) {
		$config['DefaultCacheBehavior']['FunctionAssociations'] = [
			'Quantity' => 1,
			'Items' => [
				[
					"FunctionARN" => $cloudfront_function_arn,
					"EventType" => "viewer-request"
				]
			]
		];
	}

	return $config;
}

function has_cloudfront_function() : bool {
	return get_option( 'hm-cloudfront-function', false );
}

function unlink_cloudfront_function() {
	delete_option( 'hm-cloudfront-function' );
}

function get_cloudfront_function_arn(): ?string {
	return get_option( 'hm-cloudfront-function', null );
}
/**
 * Create the CloudFront function that is responsible for the Viewer Request in setting the X-Original-Host
 *
 */
function create_cloudfront_function() : string {
	$client = get_aws_cloudfront_client();
	$name = get_current_blog_id() . '-remap-host-header';
	$function = $client->createFunction([
		'FunctionCode' => file_get_contents( __DIR__ . '/cloudfront-function.js' ),
		'FunctionConfig' => [
			'Comment' => 'Sets the X-Original-Host header.',
			'Runtime' => 'cloudfront-js-2.0',
		],
		'Name' => $name,
	]);

	$arn = $function['FunctionSummary']['FunctionMetadata']['FunctionARN'];
	$etag = $function['ETag'];

	$client->publishFunction([
		'IfMatch' => $etag,
		'Name' => $name,
	]);

	update_option( 'hm-cloudfront-function', $arn );
	return $arn;
}


function has_cloudfront_origin_request_policy() : bool {
	return get_option( 'hm-cloudfront-origin-request-policy', false );
}

function unlink_cloudfront_origin_request_policy() {
	delete_option( 'hm-cloudfront-origin-request-policy' );
}

function get_cloudfront_origin_request_policy_id(): ?string {
	return get_option( 'hm-cloudfront-origin-request-policy', null );
}
/**
 * Create or find an existing CloudFront Origin Request policy.
 *
 * @return string The Origin Request Policy Id.
 *
 */
function create_cloudfront_origin_request_policy() : string {

	$existing_policies = get_cloudfront_origin_request_policies();
	foreach ( $existing_policies as $policy_id => $sites ) {
		if ( count( $sites ) >= HM_ACM_ORIGIN_REQUEST_POLICIES_PER_DISTRIBUTION ) {
			continue;
		}

		update_option( 'hm-cloudfront-origin-request-policy', $policy_id );
	}

	$client = get_aws_cloudfront_client();
	$name = get_current_blog_id() . '-hm-acm';
	$policy = $client->createOriginRequestPolicy([
		'OriginRequestPolicyConfig' => [
			'Comment' => 'HM-ACM origin request policy',
			'Name' => $name,
			'HeadersConfig' => [
				'HeaderBehavior' => 'allViewer',
			],
			'QueryStringsConfig' => [
				'QueryStringBehavior' => 'all',
			],
			'CookiesConfig' => [
				'CookieBehavior' => 'whitelist',
				'Cookies' => [
					'Quantity' => 3,
					'Items' => [
						"hm_*",
						"wp_*",
						"wordpress_*"
					],
				]
			],
		],
	]);

	$policy_id = $policy['OriginRequestPolicy']['Id'];
	add_site_to_cloudfront_origin_request_policy( $policy_id, get_current_blog_id() );

	update_option( 'hm-cloudfront-origin-request-policy', $policy_id );
	return $policy_id;
}

/**
 * Get all the created origin request policies on the network.
 *
 * We have to batch CloudFront Origin Request policies as we're limited by both the number of total policies and the number of
 * associations between a single policy and many distributions.
 *
 * @return array<string, list<int>> A map of policy Id's to array of sites that are using the policy in their CloudFront Distributions.
 */
function get_cloudfront_origin_request_policies() : array {
	return get_site_option( 'hm-acm-origin-request-policies', [] );
}

/**
 * Add a site to the origin request policies on the network.
 */
function add_site_to_cloudfront_origin_request_policy( string $policy_id, int $site_id ) : void {
	$policies = get_cloudfront_origin_request_policies();
	$policies[ $policy_id ][] = $site_id;
	update_site_option( 'hm-acm-origin-request-policies', $policies );
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
		'region'  => 'us-east-1',
		'version' => 'latest',
	];

	$params['credentials'] = [
		'key'    => HM_ACM_AWS_KEY,
		'secret' => HM_ACM_AWS_SECRET,
	];
	$sdk = new \Aws\Sdk( $params );
	return $sdk;
}
