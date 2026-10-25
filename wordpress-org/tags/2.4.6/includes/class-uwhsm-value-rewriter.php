<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Rewrite serialized string tokens without instantiating PHP objects or losing references. */
final class UWHSM_Value_Rewriter {
    public static function rewrite($value, array $map) {
        if (!is_string($value)) {
            return $value;
        }
        if (strtr($value, $map) === $value) {
            return $value;
        }
        if (is_serialized($value)) {
            $pos = 0;
            $out = self::token($value, $pos, $map, 0);
            if ($pos !== strlen($value)) {
                throw new RuntimeException(esc_html__('Invalid serialized database value.', 'uwh-site-migrator'));
            }
            return $out;
        }
        return strtr($value, $map);
    }

    private static function token($text, &$pos, array $map, $depth) {
        if ($depth > 256) {
            throw new RuntimeException(esc_html__('Serialized data nesting is too deep.', 'uwh-site-migrator'));
        }
        $tail = substr($text, $pos);
        if (preg_match('/\A(?:N;|[bidrR]:[^;]*;)/', $tail, $m)) {
            $pos += strlen($m[0]);
            return $m[0];
        }
        if (preg_match('/\As:(\d+):"/', $tail, $m)) {
            $pos += strlen($m[0]);
            $length = (int) $m[1];
            $value = substr($text, $pos, $length);
            $pos += $length;
            if (strlen($value) !== $length || substr($text, $pos, 2) !== '";') {
                throw new RuntimeException(esc_html__('Invalid serialized string length.', 'uwh-site-migrator'));
            }
            $pos += 2;
            $value = self::rewrite($value, $map);
            return 's:' . strlen($value) . ':"' . $value . '";';
        }
        if (preg_match('/\Aa:(\d+):\{/', $tail, $m)) {
            $pos += strlen($m[0]);
            $out = $m[0];
            $count = (int) $m[1] * 2;
        } elseif (preg_match('/\AO:(\d+):"/', $tail, $m)) {
            $start = $pos;
            $pos += strlen($m[0]) + (int) $m[1];
            if (!preg_match('/\A":(\d+):\{/', substr($text, $pos), $n)) {
                throw new RuntimeException(esc_html__('Invalid serialized object.', 'uwh-site-migrator'));
            }
            $pos += strlen($n[0]);
            $out = substr($text, $start, $pos - $start);
            $count = (int) $n[1] * 2;
        } elseif (preg_match('/\AE:(\d+):"/', $tail, $m)) {
            $length = strlen($m[0]) + (int) $m[1] + 2;
            $out = substr($text, $pos, $length);
            $pos += $length;
            return $out;
        } else {
            throw new RuntimeException(esc_html__('Unsupported serialized value. Migration stopped to avoid data corruption.', 'uwh-site-migrator'));
        }
        if ($count > strlen($text)) {
            throw new RuntimeException(esc_html__('Invalid serialized item count.', 'uwh-site-migrator'));
        }
        for ($i = 0; $i < $count; $i++) {
            $out .= self::token($text, $pos, $map, $depth + 1);
        }
        if (substr($text, $pos, 1) !== '}') {
            throw new RuntimeException(esc_html__('Invalid serialized container.', 'uwh-site-migrator'));
        }
        $pos++;
        return $out . '}';
    }
}
