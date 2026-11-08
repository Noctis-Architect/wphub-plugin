<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- The spool uses direct file I/O and locks for a high-frequency queue.

final class WPHS_Spool {

	const OPTION_KEY   = 'wphs_spool_key';
	const MAX_FILE_MB  = 64;

	private static $dir = null;

	public static function ensure_dir() {
		$dir = self::dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	public static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$key = get_option( self::OPTION_KEY, '' );

		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9]{8,20}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 12, false, false ) );
			update_option( self::OPTION_KEY, $key, true );
		}

		self::$dir = trailingslashit( WP_CONTENT_DIR ) . 'wphub-analytics-' . $key;

		return self::$dir;
	}

	public static function append( array $event ) {
		$dir = self::dir();

		if ( ! is_dir( $dir ) && ! self::ensure_dir() ) {
			return false;
		}

		$file = $dir . '/spool-' . current_time( 'Ymd' ) . '.log';

		if ( @filesize( $file ) > self::MAX_FILE_MB * MB_IN_BYTES ) {
			return false;
		}

		$line = wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $line ) || '' === $line ) {
			return false;
		}

		return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	public static function files() {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '/spool-*.log.processing' );

		if ( ! is_array( $files ) ) {
			$files = array();
		}

		$live = glob( $dir . '/spool-*.log' );

		if ( is_array( $live ) ) {
			$files = array_merge( $files, $live );
		}

		usort(
			$files,
			static function ( $a, $b ) {
				$a_processing = '.processing' === substr( $a, -11 );
				$b_processing = '.processing' === substr( $b, -11 );

				if ( $a_processing !== $b_processing ) {
					return $a_processing ? -1 : 1;
				}

				return strcmp( $a, $b );
			}
		);

		return $files;
	}

	public static function claim( $file ) {
		$target = $file . '.processing';

		if ( file_exists( $target ) ) {
			return false;
		}

		return @rename( $file, $target ) ? $target : false;
	}

	public static function drop( $file ) {
		@unlink( $file );
	}

	public static function size() {
		$dir  = self::dir();
		$size = 0;

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		foreach ( (array) glob( $dir . '/spool-*' ) as $file ) {
			$size += (int) @filesize( $file );
		}

		return $size;
	}

	public static function pending_count() {
		$count = 0;

		foreach ( self::files() as $file ) {
			$handle = @fopen( $file, 'rb' );

			if ( ! $handle ) {
				continue;
			}

			while ( fgets( $handle ) !== false ) {
				$count++;
			}

			fclose( $handle );
		}

		return $count;
	}

	public static function prune_cloud( $max_mb = 25 ) {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$max   = max( 1, (int) $max_mb ) * MB_IN_BYTES;
		$files = glob( $dir . '/spool-*' );

		if ( ! is_array( $files ) ) {
			return;
		}

		$sizes = array();
		$total = 0;

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			$sizes[ $file ] = (int) @filesize( $file );
			$total         += $sizes[ $file ];
		}

		if ( $total <= $max ) {
			return;
		}

		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			if ( $total <= $max ) {
				break;
			}

			if ( '.processing' === substr( $file, -11 ) || ! isset( $sizes[ $file ] ) ) {
				continue;
			}

			if ( @unlink( $file ) ) {
				$total -= $sizes[ $file ];
			}
		}
	}

	public static function cleanup_old( $days = 3 ) {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$cutoff = time() - ( absint( $days ) * DAY_IN_SECONDS );

		foreach ( (array) glob( $dir . '/spool-*' ) as $file ) {
			$mtime = @filemtime( $file );
			if ( $mtime && $mtime < $cutoff ) {
				@unlink( $file );
			}
		}
	}
}
