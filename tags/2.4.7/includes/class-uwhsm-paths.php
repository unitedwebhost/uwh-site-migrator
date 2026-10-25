<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Portable, logical archive locations versus WordPress-controlled physical directories.
 *
 * The .uwh v1 format intentionally uses site/wp-content/ as a portable *archive key*.
 * It is NOT a physical directory assumption: the mappings below use the installation's
 * actual content, plugin and upload roots. Keeping the logical keys stable also allows
 * old archives to restore and automatic checkpoints to remain compatible.
 */
final class UWHSM_Paths {
    public static function wordpress_root() {
        // ABSPATH is the canonical WordPress core installation root, not a plugin or upload directory.
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,PluginCheck.CodeAnalysis.DirectConstants -- Full WordPress core backup/restore requires its physical installation root.
        return trailingslashit( wp_normalize_path( ABSPATH ) );
    }

    public static function content_root() {
        // WordPress has no public physical content-directory accessor; this is the
        // configurable path (unlike concatenating a fixed wp-content directory name).
        // phpcs:ignore PluginCheck.CodeAnalysis.DirectConstants -- Needed to support *custom* content locations, including paths outside ABSPATH.
        return trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
    }

    public static function plugin_root() {
        // The current plugin is installed inside the configured plugins root.
        return trailingslashit( wp_normalize_path( dirname( untrailingslashit( plugin_dir_path( UWHSM_FILE ) ) ) ) );
    }

    public static function upload_root() {
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            throw new RuntimeException( esc_html__( 'WordPress could not determine its uploads directory.', 'uwh-site-migrator' ) );
        }
        return trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
    }

    public static function upload_url() {
        $uploads = wp_upload_dir( null, false );
        return ! empty( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
    }

    private static function inside( $path, $root ) {
        $path = untrailingslashit( wp_normalize_path( (string) $path ) );
        $root = untrailingslashit( wp_normalize_path( (string) $root ) );
        return $path === $root || 0 === strpos( $path, $root . '/' );
    }

    public static function roots() {
        $roots = array(
            'site'    => self::wordpress_root(),
            'content' => self::content_root(),
            'plugins' => self::plugin_root(),
            'uploads' => self::upload_root(),
        );
        // There cannot be an unambiguous archive-to-installation mapping when
        // two different logical WordPress roots are the identical real directory.
        $normalized = array_map( 'untrailingslashit', $roots );
        if ( count( array_unique( $normalized ) ) !== count( $normalized ) ) {
            throw new RuntimeException( esc_html__( 'WordPress filesystem directories overlap completely; this layout cannot be safely migrated.', 'uwh-site-migrator' ) );
        }
        return $roots;
    }

    /** Map an archive-relative file path to a valid destination root. */
    public static function restore_target( $relative ) {
        $relative = wp_normalize_path( (string) $relative );
        if ( '' === $relative || '/' === substr( $relative, 0, 1 ) || strpos( $relative, "\0" ) !== false || strpos( $relative, "\\" ) !== false || strpos( $relative, ':' ) !== false || preg_match( '#(^|/)\.\.?(/|$)#', $relative ) ) {
            throw new RuntimeException( esc_html__( 'Unsafe restore file path.', 'uwh-site-migrator' ) );
        }
        $roots = self::roots();
        $prefixes = array(
            'wp-content/plugins/' => $roots['plugins'],
            'wp-content/uploads/' => $roots['uploads'],
            'wp-content/'         => $roots['content'],
        );
        foreach ( $prefixes as $prefix => $root ) {
            if ( 0 === strpos( $relative, $prefix ) ) {
                return $root . substr( $relative, strlen( $prefix ) );
            }
        }
        return $roots['site'] . $relative;
    }

    /**
     * A safe real filesystem scan without assuming uploads/plugins live in wp-content.
     * Four scans avoid missing moved directories; more-specific roots take priority,
     * so nested/default WordPress locations are archived just once.
     *
     * @return array<int,array{path:string,rel:string,size:int,own:bool}>
     */
    public static function files( $storage_root, $ephemeral_callback = null ) {
        $roots = self::roots();
        $storage = untrailingslashit( wp_normalize_path( $storage_root ) );
        $own = untrailingslashit( wp_normalize_path( plugin_dir_path( UWHSM_FILE ) ) );
        $skip = array(
            $storage,
            self::content_root() . 'cache',
            self::content_root() . 'upgrade',
            self::upload_root() . 'cache',
        );
        foreach ( array( 'wpvividbackups', 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backup-migration', 'wflogs', 'litespeed', 'et-cache' ) as $directory ) {
            $skip[] = self::content_root() . $directory;
        }

        // For each logical subtree, exclude the more specific physical subtrees.
        $scans = array(
            array( 'root' => $roots['site'], 'prefix' => '', 'skip_roots' => array( $roots['content'], $roots['plugins'], $roots['uploads'] ) ),
            array( 'root' => $roots['content'], 'prefix' => 'wp-content/', 'skip_roots' => array( $roots['plugins'], $roots['uploads'] ) ),
            array( 'root' => $roots['plugins'], 'prefix' => 'wp-content/plugins/', 'skip_roots' => array( $roots['uploads'] ) ),
            array( 'root' => $roots['uploads'], 'prefix' => 'wp-content/uploads/', 'skip_roots' => array() ),
        );
        $out = array();
        $seen = array();
        foreach ( $scans as $scan ) {
            $root = $scan['root'];
            if ( ! is_dir( $root ) ) {
                // Some WordPress installs omit uploads until the first upload.
                if ( 'wp-content/uploads/' === $scan['prefix'] ) {
                    continue;
                }
                throw new RuntimeException( esc_html__( 'A required WordPress directory is missing.', 'uwh-site-migrator' ) );
            }
            // Nested or overlapping roots may not map back into the same archive prefix.
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
            foreach ( $iterator as $file ) {
                if ( $file->isLink() ) {
                    throw new RuntimeException( esc_html__( 'Symbolic links are not supported: ', 'uwh-site-migrator' ) . esc_html( $file->getPathname() ) );
                }
                if ( ! $file->isFile() ) {
                    continue;
                }
                $path = wp_normalize_path( $file->getPathname() );
                $blocked = false;
                foreach ( array_merge( $skip, $scan['skip_roots'] ) as $excluded ) {
                    if ( self::inside( $path, $excluded ) && ! self::inside( $root, $excluded ) ) {
                        $blocked = true;
                        break;
                    }
                }
                if ( $blocked ) {
                    continue;
                }
                if ( isset( $seen[ $path ] ) ) {
                    continue;
                }
                $relative = ltrim( substr( $path, strlen( $root ) ), '/' );
                $archive_rel = $scan['prefix'] . $relative;
                if ( '' === $relative || in_array( $archive_rel, array( 'wp-config.php', '.user.ini', 'php.ini', '.htaccess', '.maintenance' ), true ) || ( $ephemeral_callback && call_user_func( $ephemeral_callback, $archive_rel ) ) ) {
                    continue;
                }
                // Never include a misplaced storage directory in a portable backup.
                if ( self::inside( $path, $storage ) ) {
                    continue;
                }
                $seen[ $path ] = true;
                $out[] = array( 'path' => $path, 'rel' => $archive_rel, 'size' => (int) $file->getSize(), 'own' => self::inside( $path, $own ) );
            }
        }
        return $out;
    }

    /** Restrict restore writes to the roots owned by this WordPress installation. */
    public static function assert_safe_destination( $path, $temporary_storage_root = '' ) {
        $target = wp_normalize_path( (string) $path );
        $allowed = self::roots();
        // The optional custom UWHSM_STORAGE_PATH can point outside uploads.
        // It remains a trusted location only for the plugin's own extraction/lock files.
        if ( '' !== (string) $temporary_storage_root ) {
            $allowed['storage'] = trailingslashit( wp_normalize_path( $temporary_storage_root ) );
        }
        $anchor = '';
        foreach ( $allowed as $root ) {
            if ( self::inside( $target, $root ) && strlen( $root ) > strlen( $anchor ) ) {
                $anchor = $root;
            }
        }
        if ( '' === $anchor ) {
            throw new RuntimeException( esc_html__( 'The restore target is outside WordPress directories.', 'uwh-site-migrator' ) );
        }
        $root = untrailingslashit( $anchor );
        while ( strlen( $target ) >= strlen( $root ) ) {
            if ( is_link( $target ) ) {
                throw new RuntimeException( esc_html__( 'Refusing to overwrite a symbolic link: ', 'uwh-site-migrator' ) . esc_html( $target ) );
            }
            if ( $target === $root ) {
                break;
            }
            $target = dirname( $target );
        }
    }
}
