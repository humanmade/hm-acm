<?php
/**
 * HM ACM WP-CLI commands
 */

declare( strict_types=1 );

namespace HM\ACM\CLI\Commands;

use Exception;
use Throwable;
use WP_CLI;

use function HM\ACM\create_certificate;
use function HM\ACM\create_cloudfront_distribution;
use function HM\ACM\get_suggested_domains;
use function HM\ACM\has_certificate;
use function HM\ACM\has_verified_certificate;
use function HM\ACM\refresh_certificate;
use function HM\ACM\unlink_certificate;

/**
 * Class for registering ACM specific WP-CLI commands.
 */
class Acm {

	/**
	 * The action to take.
	 *
	 * @var string $action
	 */
	private string $action = '';

	/**
	 * Whether to run the command on the network.
	 *
	 * @var bool
	 */
	private bool $network = false;

	/**
	 * Whether to run the command in verbose mode.
	 *
	 * @var bool
	 */
	private bool $verbose = false;

	/**
	 * Whether to run the command in dry run mode.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * WP_Site_Query args.
	 *
	 * @var array
	 */
	private array $query_args = [];

	/**
	 * Certificate records.
	 *
	 * @var array
	 */
	private array $certificate_records = [];

	/**
	 * Sets up actions and filters.
	 */
	public function __construct() {
	}

	/**
	 * Validate the inputs for the command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	private function validate_inputs( array $args, array $assoc_args ): void {
		$this->network = isset( $assoc_args['network'] );
		$this->verbose = isset( $assoc_args['verbose'] );
		$this->dry_run = isset( $assoc_args['dry_run'] );

		$this->action = $args[0];

		if ( empty( $this->action ) ) {
			WP_CLI::error( 'An action is required.' );
		}

		if ( ! in_array( $this->action, [ 'create-cert', 'verify-cert', 'delete-cert', 'create-cloudfront' ], true ) ) {
			WP_CLI::error( 'Invalid action provided.' );
		}

		if ( $this->dry_run === true ) {
			WP_CLI::line( '~~~~ Command Running in DRY RUN mode ~~~~' );
		} else {
			WP_CLI::line( '~~~~ Command running LIVE ~~~~' );
		}

		if ( ! $this->network && empty( $assoc_args['include'] ) ) {
			WP_CLI::confirm( 'HM ACM command will be executed for the current site since no site ID was provided.' );
		}

		if ( $this->network && ! empty( $assoc_args['include'] ) ) {
			WP_CLI::warning( 'HM ACM command will be executed network wide so site ID(s) provided via include parameter will be ignored.' );
		}

		$this->query_args = [
			'fields' => 'ids',
			'public' => 1,
			'no_found_rows' => false,
		];

		if ( ! empty( $assoc_args['exclude'] ) ) {
			$this->query_args['site__not_in'] = explode( ',', $assoc_args['exclude'] );
		}

		if ( $this->network ) {
			WP_CLI::confirm( 'Are you sure you want to run this command for all sites on the network?', $assoc_args );
		} else {
			$site_ids = $assoc_args['include'] ? explode( ',', $assoc_args['include'] ) : [ get_current_blog_id() ];

			$this->query_args['site__in'] = $site_ids;

			WP_CLI::confirm( sprintf( 'Are you sure you want to run this command for site(s) %s?', implode( ', ', $site_ids ) ), $assoc_args );
		}
	}

	/**
	 * Perform an ACM related action.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : The action to take.
	 *
	 * [--domains=<domains>]
	 * : Comma separated list of domains to create the certificate for. Required for create-cert action.
	 *
	 * [--include=<site-id>]
	 * : Comma separated list of IDs of the sites to perform the action on. Default to current site.
	 *
	 * [--exclude=<site-id>]
	 * : Comma separated list of IDs of the sites to exclude from the action. Useful if you want the command to run network wide but exclude for example main site.
	 *
	 * [--rate=<rate>]
	 * : How many sites to process at a time before sleeping for 1 second. Default is 5.
	 *
	 * [--network]
	 * : Whether to perform the action on all sites on the network.
	 *
	 * [--verbose]
	 * : Allows for a more verbose output to the I/O
	 *
	 * [--dry_run]
	 * : Test run for the command without changing any data
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function acm( array $args, array $assoc_args ) {
		$this->validate_inputs( $args, $assoc_args );

		try {
			$found_sites = 0;
			$total_changed_sites = 0;
			$progress_bar = null;
			$paged = 1;
			$sites_per_page = 100;

			do {
				$this->query_args['number'] = $sites_per_page;
				$this->query_args['paged'] = $paged;
				$this->query_args['offset'] = ( $paged - 1 ) * $sites_per_page;

				$query = new \WP_Site_Query( $this->query_args );

				if ( ! $progress_bar ) {
					$found_sites = $query->found_sites;
					$progress_bar = WP_CLI\Utils\make_progress_bar( 'Progress: ', $found_sites );
				}

				if ( empty( $query->sites ) ) {
					break;
				}

				$rate = $assoc_args['rate'] ? intval( $assoc_args['rate'] ) : 5; // How many sites to process at a time before sleeping for 1 second.

				for ( $i = 0; $i < count( $query->sites ); $i++ ) {
					if ( $i % $rate === 0 ) {
						// Sleep for 1 second every X sites to avoid rate limiting.
						sleep( 1 );
					}

					$site_id = $query->sites[ $i ];

					if ( $this->verbose ) {
						WP_CLI::log( sprintf( 'Running HM ACM %1$s command for site %2$d', $this->action, $site_id ) );
					}

					switch_to_blog( $site_id );

					$result = false;

					if ( $this->dry_run ) {
						$result = true; // Dry run mode, so just assume everything goes well.
					} else {
						switch ( $this->action ) {
							case 'create-cert':
								$result = $this->create_cert( $site_id, $assoc_args );
								break;
							case 'verify-cert':
								$result = $this->verify_cert( $site_id );
								break;
							case 'delete-cert':
								$result = $this->delete_cert( $site_id );
								break;
							case 'create-cloudfront':
								$result = $this->create_cloudfront( $site_id );
								break;
							default:
								break;
						}
					}

					if ( $result ) {
						$total_changed_sites++;
					}

					if ( $this->verbose && $result ) {
						WP_CLI::success( sprintf( 'Site %1$d HM ACM %2$s command result was successful.', $site_id, $this->action ) );
					}

					if ( $this->verbose && ! $result ) {
						WP_CLI::warning( sprintf( 'Site %1$d HM ACM %2$s command result failed.', $site_id, $this->action ) );
					}

					restore_current_blog();

					if ( $this->verbose === false ) {
						$progress_bar->tick();
					}
				}

				$paged++;
			} while ( ! empty( $query->sites ) );

			if ( $this->verbose === false ) {
				$progress_bar->finish();
			}

			if ( empty( $total_changed_sites ) ) {
				WP_CLI::error( 'No sites were affected by the HM ACM command.' );
			} else {
				WP_CLI::success( sprintf( 'Successfully executed HM ACM command for %1$d/%2$d sites.', $total_changed_sites, $found_sites ) );
			}

			switch ( $this->action ) {
				case 'create-cert':
					$this->output_cert_records_csv();
					break;
				default:
					break;
			}
		} catch ( Throwable $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Create ACM SSL cert for a site.
	 *
	 * @param int   $site_id The ID of the site.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return boolean
	 */
	private function create_cert( int $site_id, array $assoc_args ): bool {
		if ( has_certificate() ) {
			if ( $this->verbose ) {
				WP_CLI::success( sprintf( 'Site %d already has an SSL certificate.', $site_id ) );
			}
			return true;
		}

		$domains = [];

		if ( empty( $assoc_args['domains'] ) ) {
			/**
			 * Allow for domains to be passed via the suggested domains filter.
			 * Useful for when the domains aren't typed via the command but need to be retrieved from database.
			 */
			$domains = get_suggested_domains();
		} else {
			$domains = explode( ',', $assoc_args['domains'] );
		}

		if ( empty( $domains ) ) {
			if ( $this->verbose ) {
				WP_CLI::warning( sprintf( 'SSL certificate cannot be created because no domains were provided for site %d.', $site_id ) );
			}
			return false;
		}

		$certificate = (array) create_certificate( $domains );

		if ( $certificate ) {
			// Add cert details to CSV row.
			foreach ( $certificate['DomainValidationOptions'] as $domain ) {
				$this->certificate_records[] = [
					'Site ID' => $site_id,
					'Site domain' => $certificate['DomainName'],
					'Record name' => $domain['ResourceRecord']['Name'],
					'Record type' => $domain['ResourceRecord']['Type'],
					'Record Value' => $domain['ResourceRecord']['Value'],
				];
			}

			return true;
		}

		return false;
	}

	/**
	 * Write the CSV file with the certificate DNS records.
	 *
	 * @return void
	 */
	private function output_cert_records_csv(): void {
		if ( empty( $this->certificate_records ) ) {
			if ( $this->verbose ) {
				WP_CLI::warning( 'No SSL certificates were created so CSV file with records will not be created.' );
			}
			return;
		}

		$csv_file = 'ssl_certificate_records.csv';
		$csv_file_path = wp_upload_dir()['basedir'] . '/' . $csv_file;

		$csv = fopen( $csv_file_path, 'w' );

		// Add headers to CSV.
		fputcsv( $csv, array_keys( $this->certificate_records[0] ) );

		foreach ( $this->certificate_records as $certificate ) {
			fputcsv( $csv, $certificate );
		}
	}

	/**
	 * Verify ACM SSL cert for a site.
	 *
	 * @param int $site_id The ID of the site.
	 * @return boolean
	 */
	private function verify_cert( int $site_id ): bool {
		if ( ! has_certificate() ) {
			if ( $this->verbose ) {
				WP_CLI::warning( sprintf( 'Site %d does not have an SSL certificate so nothing to verify.', $site_id ) );
			}
			return false;
		}

		refresh_certificate();

		return has_verified_certificate();
	}

	/**
	 * Delete ACM SSL cert for a site.
	 *
	 * @param int $site_id The ID of the site.
	 * @return boolean
	 */
	private function delete_cert( int $site_id ): bool {
		if ( ! has_certificate() ) {
			if ( $this->verbose ) {
				WP_CLI::warning( sprintf( 'Site %d does not have an SSL certificate so nothing to delete.', $site_id ) );
			}
			return false;
		}

		unlink_certificate(); // This just removes the option in WP and allows for another cert to be requested.

		return true;
	}

	/**
	 * Create CloudFront distribution for a site.
	 *
	 * @param int $site_id The ID of the site.
	 * @return boolean
	 */
	private function create_cloudfront( int $site_id ): bool {
		if ( ! has_verified_certificate() ) {
			if ( $this->verbose ) {
				WP_CLI::warning( sprintf( 'Site %d does not have a verified ACM SSL certificate so CloudFront distribution cannot be created.', $site_id ) );
			}
			return false;
		}

		try {
			create_cloudfront_distribution();
		} catch ( Exception $e ) {
			if ( $this->verbose ) {
				WP_CLI::error( sprintf( 'Failed to create CloudFront distribution for site %d. Error: %s', $site_id, $e->getMessage() ) );
			}
			return false;
		}

		return true;
	}
}
