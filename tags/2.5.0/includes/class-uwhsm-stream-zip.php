<?php
/**
 * Experimental append-only ZIP64 writer for fresh backup jobs.
 *
 * Deliberately opt-in. Existing .uwh archives, interrupted legacy jobs and
 * restore/checkpoint writers retain the production ZipArchive implementation.
 * No external commands, shell execution or extra PHP extensions besides zlib.
 *
 * @package UWH_Site_Migrator
 */
if (!defined('ABSPATH')) {
    exit;
}

final class UWHSM_Stream_Zip {
    const FLAG = 0x0808; // UTF-8 names + data descriptor.

    /** All 64-bit little-endian values are serialized explicitly. */
    private static function u64($value) {
        if ($value < 0) {
            throw new RuntimeException('Negative ZIP64 offset.');
        }
        return pack('V2', $value & 0xffffffff, ($value >> 32) & 0xffffffff);
    }

    private static function exact($handle, $bytes) {
        $length = strlen($bytes);
        for ($i = 0; $i < $length;) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            $written = @fwrite($handle, substr($bytes, $i, min(1024 * 1024, $length - $i)));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Unable to write the append-only backup stream.');
            }
            $i += $written;
        }
    }

    private static function valid_name($name) {
        return is_string($name) && $name !== '' && strlen($name) <= 65535
            && strpos($name, "\0") === false && strpos($name, '\\') === false
            && $name[0] !== '/' && !preg_match('#(?:^|/)\.\.(?:/|$)#', $name);
    }

    private static function file_times($time) {
        $time = is_numeric($time) ? (int) $time : time();
        // Standard ZIP DOS timestamp, kept in local and central headers.
        $year = (int) gmdate('Y', $time);
        if ($year < 1980 || $year > 2107) {
            $time = time();
        }
        $y = max(1980, min(2107, (int) gmdate('Y', $time))) - 1980;
        $m = (int) gmdate('n', $time);
        $d = (int) gmdate('j', $time);
        $h = (int) gmdate('G', $time);
        $min = (int) gmdate('i', $time);
        $sec = (int) gmdate('s', $time);
        return array((($h & 31) << 11) | (($min & 63) << 5) | (($sec >> 1) & 31),
            (($y & 127) << 9) | (($m & 15) << 5) | ($d & 31));
    }

    public static function begin($path, $journal, $manifest) {
        if (is_file($path) || is_file($journal) || is_link($path) || is_link($journal)) {
            throw new RuntimeException('A backup stream already exists at the requested path.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $fp = @fopen($path, 'x+b');
        if (!$fp) {
            throw new RuntimeException('Cannot initialize private backup stream.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        fclose($fp);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $jp = @fopen($journal, 'xb');
        if (!$jp) {
            wp_delete_file($path);
            throw new RuntimeException('Cannot initialize private ZIP entry journal.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        fclose($jp);
        return self::append($path, $journal, 0, 0, 'manifest.json', null, $manifest, null, null);
    }

    /**
     * Append a complete, verified ZIP64 entry. All bytes beyond the last
     * committed state position are truncated before retrying a crashed batch.
     * Return updated stream/journal offsets; caller commits both to job state.
     */
    public static function append($path, $journal, $offset, $journal_bytes, $name, $source, $contents, $expected_size, $expected_sha) {
        if (!self::valid_name($name) || (null === $source && !is_string($contents))) {
            throw new RuntimeException('Invalid ZIP entry request.');
        }
        if (is_link($path) || is_link($journal)) {
            throw new RuntimeException('A backup stream path is a symbolic link.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $fp = @fopen($path, 'c+b');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $jp = @fopen($journal, 'c+b');
        if (!$fp || !$jp) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            if ($fp) { fclose($fp); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            if ($jp) { fclose($jp); }
            throw new RuntimeException('Cannot open private backup stream and journal.');
        }
        try {
            $current = @fstat($fp);
            $journal_stat = @fstat($jp);
            if (!$current || !$journal_stat || $current['size'] < $offset || $journal_stat['size'] < $journal_bytes) {
                throw new RuntimeException('The backup archive/journal is shorter than its committed progress. Do not resume this damaged job.');
            }
            if (!@ftruncate($fp, (int) $offset) || !@ftruncate($jp, (int) $journal_bytes)) {
                throw new RuntimeException('Cannot recover interrupted backup append.');
            }
            if (0 !== @fseek($fp, (int) $offset) || 0 !== @fseek($jp, (int) $journal_bytes)) {
                throw new RuntimeException('Cannot seek to committed backup position.');
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $store = in_array($ext, array('jpg','jpeg','png','gif','webp','avif','heic','heif','jxl','svgz','mp4','m4v','mov','avi','mkv','webm','ogv','3gp','mp3','m4a','aac','ogg','opus','flac','zip','gz','tgz','bz2','xz','zst','rar','7z','pdf','woff','woff2'), true);
            $method = $store ? 0 : 8;
            $deflater = $store ? null : deflate_init(ZLIB_ENCODING_RAW, array('level' => 1));
            if (!$store && !$deflater) {
                throw new RuntimeException('zlib streaming compression is unavailable.');
            }
            list($dos_time, $dos_date) = self::file_times($source !== null ? @filemtime($source) : time());
            $name_bytes = strlen($name);
            $local_extra = pack('vv', 1, 16) . self::u64(0) . self::u64(0);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 45, self::FLAG, $method, $dos_time, $dos_date,
                0, 0xffffffff, 0xffffffff, $name_bytes, strlen($local_extra));
            self::exact($fp, $local . $name . $local_extra);
            $file = null;
            if (null !== $source) {
                if (!is_file($source) || is_link($source)) {
                    throw new RuntimeException('Source file disappeared or is a symlink.');
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
                $file = @fopen($source, 'rb');
                if (!$file) {
                    throw new RuntimeException('Could not read source file.');
                }
            }
            $sha = hash_init('sha256');
            $crc = hash_init('crc32b');
            $uncompressed = 0;
            $compressed = 0;
            $position = 0;
            try {
                while (true) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
                    $data = $file ? @fread($file, 262144) : substr($contents, $position, 262144);
                    if ($data === false) {
                        throw new RuntimeException('Source file read failed during streaming.');
                    }
                    if ($data === '') {
                        if (!$file || feof($file)) { break; }
                        throw new RuntimeException('Source file read stopped without EOF.');
                    }
                    $n = strlen($data);
                    $uncompressed += $n;
                    $position += $n;
                    hash_update($sha, $data);
                    hash_update($crc, $data);
                    $output = $store ? $data : deflate_add($deflater, $data, ZLIB_NO_FLUSH);
                    if (!is_string($output)) {
                        throw new RuntimeException('Streaming ZIP compression failed.');
                    }
                    self::exact($fp, $output);
                    $compressed += strlen($output);
                }
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
                if ($file) { fclose($file); }
            }
            if (!$store) {
                $tail = deflate_add($deflater, '', ZLIB_FINISH);
                if (!is_string($tail)) {
                    throw new RuntimeException('ZIP compression flush failed.');
                }
                self::exact($fp, $tail);
                $compressed += strlen($tail);
            }
            $digest = hash_final($sha);
            $crc_hex = hash_final($crc);
            if ((null !== $expected_size && (int) $expected_size !== $uncompressed)
                || (is_string($expected_sha) && !hash_equals(strtolower($expected_sha), $digest))) {
                throw new RuntimeException('Source file changed during ZIP writing. The uncommitted entry was discarded.');
            }
            $crc_int = (int) hexdec($crc_hex);
            self::exact($fp, pack('VV', 0x08074b50, $crc_int) . self::u64($compressed) . self::u64($uncompressed));
            if (!@fflush($fp)) {
                throw new RuntimeException('Could not flush the ZIP entry to disk.');
            }
            $new_offset = @ftell($fp);
            if (false === $new_offset) {
                throw new RuntimeException('Cannot retrieve the new ZIP position.');
            }
            $entry = array('n' => $name, 'o' => $offset, 'c' => $compressed, 'u' => $uncompressed,
                'crc' => $crc_hex, 'm' => $method, 't' => $dos_time, 'd' => $dos_date);
            // A malformed filename can make json_encode() return false. Never
            // commit a ZIP entry without a valid resume journal record.
            $entry_json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $entry_json) {
                throw new RuntimeException('Cannot record ZIP entry with an invalid UTF-8 filename.');
            }
            self::exact($jp, $entry_json . "\n");
            if (!@fflush($jp)) {
                throw new RuntimeException('Could not flush ZIP entry journal.');
            }
            $new_journal = @ftell($jp);
            return array('offset' => (int) $new_offset, 'journal_bytes' => (int) $new_journal,
                'sha256' => $digest, 'size' => $uncompressed, 'compressed' => $compressed);
        } catch (Throwable $e) {
            @ftruncate($fp, (int) $offset);
            @ftruncate($jp, (int) $journal_bytes);
            throw $e;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            fclose($fp);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            fclose($jp);
        }
    }

    /** Write the ZIP64 directory ONCE, after every file is committed. */
    public static function finish($path, $journal, $offset, $journal_bytes) {
        if (is_link($path) || is_link($journal)) {
            throw new RuntimeException('Unsafe backup ZIP path.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $fp = @fopen($path, 'c+b');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
        $jp = @fopen($journal, 'rb');
        if (!$fp || !$jp) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            if ($fp) { fclose($fp); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            if ($jp) { fclose($jp); }
            throw new RuntimeException('Could not open ZIP for final directory.');
        }
        try {
            $st = fstat($fp); $jst = fstat($jp);
            if (!$st || !$jst || $st['size'] < $offset || $jst['size'] < $journal_bytes) {
                throw new RuntimeException('Backup stream or journal is shorter than committed state.');
            }
            if (!ftruncate($fp, $offset) || fseek($fp, $offset) !== 0) {
                throw new RuntimeException('Could not resume ZIP finalization.');
            }
            $directory_start = $offset;
            $count = 0;
            while (ftell($jp) < $journal_bytes) {
                $line = fgets($jp);
                if ($line === false || ftell($jp) > $journal_bytes) {
                    throw new RuntimeException('ZIP entry journal is incomplete.');
                }
                $e = json_decode($line, true);
                if (!is_array($e) || !self::valid_name($e['n'] ?? '') || !isset($e['o'], $e['c'], $e['u'], $e['crc'], $e['m'], $e['t'], $e['d'])) {
                    throw new RuntimeException('ZIP entry journal is malformed.');
                }
                $name = $e['n'];
                $extra = pack('vv', 1, 24) . self::u64((int) $e['u']) . self::u64((int) $e['c']) . self::u64((int) $e['o']);
                $record = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, 45, self::FLAG, (int) $e['m'],
                    (int) $e['t'], (int) $e['d'], (int) hexdec($e['crc']), 0xffffffff, 0xffffffff,
                    strlen($name), strlen($extra), 0, 0, 0, 0, 0xffffffff);
                self::exact($fp, $record . $name . $extra);
                $count++;
            }
            if ($count < 1) {
                throw new RuntimeException('Cannot finish an empty ZIP archive.');
            }
            $central_end = ftell($fp);
            $central_bytes = $central_end - $directory_start;
            self::exact($fp, pack('V', 0x06064b50) . self::u64(44) . pack('vvVV', 45, 45, 0, 0)
                . self::u64($count) . self::u64($count) . self::u64($central_bytes) . self::u64($directory_start));
            self::exact($fp, pack('VV', 0x07064b50, 0) . self::u64($central_end) . pack('V', 1));
            self::exact($fp, pack('VvvvvVVv', 0x06054b50, 0, 0, min(65535, $count), min(65535, $count),
                min(0xffffffff, $central_bytes), min(0xffffffff, $directory_start), 0));
            if (!fflush($fp)) {
                throw new RuntimeException('Could not flush final ZIP directory.');
            }
            return (int) ftell($fp);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            fclose($jp);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native seekable binary streams, atomic ZIP/journal offsets and partial writes are required; WP_Filesystem has no equivalent stream handle API.
            fclose($fp);
        }
    }
}
