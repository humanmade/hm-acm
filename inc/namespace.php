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
	$result = get_aws_cloudfront_client()->createDistribution( [
		'DistributionConfig' => get_cloudfront_distribution_config(),
	] );
	update_option( 'hm-cloudfront-distribution', $result['Distribution'] );
}

function update_cloudfront_distribution_config() {
	$current_distribution = get_aws_cloudfront_client()->getDistribution([
		'Id' => get_cloudfront_distribution()['Id'],
	]);
	$result = get_aws_cloudfront_client()->updateDistribution( [
		'DistributionConfig' => get_cloudfront_distribution_config(),
		'Id' => get_cloudfront_distribution()['Id'],
		'IfMatch' => $current_distribution['ETag'],
	] );
	update_option( 'hm-cloudfront-distribution', $result['Distribution'] );
}

function unlink_cloudfront_distribution() {
	delete_option( 'hm-cloudfront-distribution' );
}

function get_cloudfront_distribution_config() : array {
	$certificate = get_certificate();
	$s3_uploads_location = explode( '/', S3_UPLOADS_BUCKET );
	$domains = array_unique( array_merge( [ $certificate['DomainName'] ], $certificate['SubjectAlternativeNames'] ) );
	return [
		'CallerReference' => site_url(),
		'Aliases' => [
			'Items' => $domains,
			'Quantity' => count( $domains ),
		],
		'DefaultRootObject' => '',
		'Origins' => [
			'Quantity' => 2,
			'Items' => [
				[
					'Id' => 'S3-Uploads',
					'DomainName' => $s3_uploads_location[0] . '.s3.amazonaws.com',
					'OriginPath' => '/' . $s3_uploads_location[1],
					'CustomHeaders' => [
						'Quantity' => 0,
					],
					'S3OriginConfig' => [
						'OriginAccessIdentity' => '',
					],
				],
				[
					'Id' => 'web',
					'DomainName' => HM_ACM_UPSTREAM_DOMAIN,
					'OriginPath' => '',
					'CustomHeaders' => [
						'Quantity' => 0,
					],
					'CustomOriginConfig' => [
						'HTTPPort' => 80,
						'HTTPSPort' => 443,
						'OriginProtocolPolicy' => 'http-only',
						'OriginSslProtocols' => [
							'Quantity' => 2,
							'Items' => [
								'SSLv3',
								'TLSv1',
							],
						],
						'OriginReadTimeout' => 60,
						'OriginKeepaliveTimeout' => 5,
					],
				],
			],
		],
		'DefaultCacheBehavior' => [
			'TargetOriginId' => 'web',
			'ForwardedValues' => [
				'QueryString' => true,
				'Cookies' => [
					'Forward' => 'whitelist',
					'WhitelistedNames' => [
						'Quantity' => 5,
						'Items' => [
							'comment_*',
							'hm_*',
							'wordpress_*',
							'wp-*',
							'wp_*',
						],
					],
				],
				'Headers' => [
					'Quantity' => 3,
					'Items' => [
						'Authorization',
						'CloudFront-Forwarded-Proto',
						'Host',
					],
				],
				'QueryStringCacheKeys' => [
					'Quantity' => 0,
				],
			],
			'TrustedSigners' => [
				'Enabled' => false,
				'Quantity' => 0,
			],
			'ViewerProtocolPolicy' => 'redirect-to-https',
			'MinTTL' => '0',
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
			'DefaultTTL' => '0',
			'MaxTTL' => '31536000',
			'Compress' => false,
			'FieldLevelEncryptionId' => '',
			'LambdaFunctionAssociations' => [
				'Quantity' => 0,
			],
		],
		'CacheBehaviors' => [
			'Quantity' => 2,
			'Items' => [
				[
					'PathPattern' => '/uploads/*',
					'TargetOriginId' => 'S3-Uploads',
					'ForwardedValues' => [
						'QueryString' => true,
						'Cookies' => [
							'Forward' => 'none',
						],
						'Headers' => [
							'Quantity' => 3,
							'Items' => [
								'Access-Control-Request-Headers',
								'Access-Control-Request-Method',
								'Origin',
							],
						],
						'QueryStringCacheKeys' => [
							'Quantity' => 0,
						],
					],
					'TrustedSigners' => [
						'Enabled' => false,
						'Quantity' => 0,
					],
					'ViewerProtocolPolicy' => 'allow-all',
					'MinTTL' => '0',
					'AllowedMethods' => [
						'Quantity' => 2,
						'Items' => [
							'HEAD',
							'GET',
						],
						'CachedMethods' => [
							'Quantity' => 2,
							'Items' => [
								'HEAD',
								'GET',
							],
						],
					],
					'SmoothStreaming' => false,
					'DefaultTTL' => '31530000',
					'MaxTTL' => '31536000',
					'Compress' => false,
					'FieldLevelEncryptionId' => '',
					'LambdaFunctionAssociations' => [
						'Quantity' => 0,
					],
				],
				[
					'PathPattern' => '/blogs.dir/*',
					'TargetOriginId' => 'S3-Uploads',
					'ForwardedValues' => [
						'QueryString' => true,
						'Cookies' => [
							'Forward' => 'none',
						],
						'Headers' => [
							'Quantity' => 3,
							'Items' => [
								'Access-Control-Request-Headers',
								'Access-Control-Request-Method',
								'Origin',
							],
						],
						'QueryStringCacheKeys' => [
							'Quantity' => 0,
						],
					],
					'TrustedSigners' => [
						'Enabled' => false,
						'Quantity' => 0,
					],
					'ViewerProtocolPolicy' => 'allow-all',
					'MinTTL' => '0',
					'AllowedMethods' => [
						'Quantity' => 3,
						'Items' => [
							'HEAD',
							'GET',
							'OPTIONS',
						],
						'CachedMethods' => [
							'Quantity' => 2,
							'Items' => [
								'HEAD',
								'GET',
							],
						],
					],
					'SmoothStreaming' => false,
					'DefaultTTL' => '31530000',
					'MaxTTL' => '31536000',
					'Compress' => false,
					'FieldLevelEncryptionId' => '',
					'LambdaFunctionAssociations' => [
						'Quantity' => 0,
					],
				],
			],
		],
		'CustomErrorResponses' => [
			'Quantity' => 6,
			'Items' => [
				[
					'ErrorCode' => 400,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '10',
				],
				[
					'ErrorCode' => 404,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '300',
				],
				[
					'ErrorCode' => 500,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '0',
				],
				[
					'ErrorCode' => 502,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '0',
				],
				[
					'ErrorCode' => 503,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '0',
				],
				[
					'ErrorCode' => 504,
					'ResponsePagePath' => '',
					'ResponseCode' => '',
					'ErrorCachingMinTTL' => '0',
				],
			],
		],
		'Comment' => '',
		'PriceClass' => 'PriceClass_All',
		'Enabled' => true,
		'ViewerCertificate' => [
			'ACMCertificateArn' => $certificate['CertificateArn'],
			'SSLSupportMethod' => 'sni-only',
			'MinimumProtocolVersion' => 'TLSv1',
		],
		'HttpVersion' => 'http2',
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
