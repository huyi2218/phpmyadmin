<?php
/**
 * Config file generator
 */

declare(strict_types=1);

namespace PhpMyAdmin\Setup;

use PhpMyAdmin\Config\ConfigFile;

use function array_keys;
use function count;
use function gmdate;
use function implode;
use function is_array;
use function mb_strpos;
use function preg_replace;
use function strtr;
use function var_export;

use const DATE_RFC1123;

/**
 * Config file generation class
 */
class ConfigGenerator
{
    /**
     * Creates config file
     *
     * @param ConfigFile $cf Config file instance
     *
     * @return string
     */
    public static function getConfigFile(ConfigFile $cf)
    {
        $crlf = isset($_SESSION['eol']) && $_SESSION['eol'] === 'win'
            ? "\r\n"
            : "\n";
        $conf = $cf->getConfig();

        // header
        $ret = '<?php' . $crlf
            . '/**' . $crlf
            . ' * Generated configuration file' . $crlf
            . ' * Generated by: phpMyAdmin '
                . $GLOBALS['PMA_Config']->get('PMA_VERSION')
                . ' setup script' . $crlf
            . ' * Date: ' . gmdate(DATE_RFC1123) . $crlf
            . ' */' . $crlf . $crlf;

        //servers
        if (! empty($conf['Servers'])) {
            $ret .= self::getServerPart($cf, $crlf, $conf['Servers']);
            unset($conf['Servers']);
        }

        // other settings
        $persistKeys = $cf->getPersistKeysMap();

        foreach ($conf as $k => $v) {
            $k = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $k);
            $ret .= self::getVarExport($k, $v, $crlf);
            if (! isset($persistKeys[$k])) {
                continue;
            }

            unset($persistKeys[$k]);
        }
        // keep 1d array keys which are present in $persist_keys (config.values.php)
        foreach (array_keys($persistKeys) as $k) {
            if (mb_strpos($k, '/') !== false) {
                continue;
            }

            $k = preg_replace('/[^A-Za-z0-9_]/', '_', $k);
            $ret .= self::getVarExport($k, $cf->getDefault($k), $crlf);
        }

        return $ret . $crlf;
    }

    /**
     * Returns exported configuration variable
     *
     * @param string $var_name  configuration name
     * @param mixed  $var_value configuration value(s)
     * @param string $crlf      line ending
     *
     * @return string
     */
    private static function getVarExport($var_name, $var_value, $crlf)
    {
        if (! is_array($var_value) || empty($var_value)) {
            return "\$cfg['" . $var_name . "'] = "
                . var_export($var_value, true) . ';' . $crlf;
        }
        $ret = '';
        if (self::isZeroBasedArray($var_value)) {
            $ret = "\$cfg['" . $var_name . "'] = "
                . self::exportZeroBasedArray($var_value, $crlf)
                . ';' . $crlf;
        } else {
            // string keys: $cfg[key][subkey] = value
            foreach ($var_value as $k => $v) {
                $k = preg_replace('/[^A-Za-z0-9_]/', '_', $k);
                $ret .= "\$cfg['" . $var_name . "']['" . $k . "'] = "
                    . var_export($v, true) . ';' . $crlf;
            }
        }

        return $ret;
    }

    /**
     * Check whether $array is a continuous 0-based array
     *
     * @param array $array Array to check
     *
     * @return bool
     */
    private static function isZeroBasedArray(array $array)
    {
        for ($i = 0, $nb = count($array); $i < $nb; $i++) {
            if (! isset($array[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exports continuous 0-based array
     *
     * @param array  $array Array to export
     * @param string $crlf  Newline string
     *
     * @return string
     */
    private static function exportZeroBasedArray(array $array, $crlf)
    {
        $retv = [];
        foreach ($array as $v) {
            $retv[] = var_export($v, true);
        }
        $ret = 'array(';
        if (count($retv) <= 4) {
            // up to 4 values - one line
            $ret .= implode(', ', $retv);
        } else {
            // more than 4 values - value per line
            $imax = count($retv);
            for ($i = 0; $i < $imax; $i++) {
                $ret .= ($i > 0 ? ',' : '') . $crlf . '    ' . $retv[$i];
            }
        }

        return $ret . ')';
    }

    /**
     * Generate server part of config file
     *
     * @param ConfigFile $cf      Config file
     * @param string     $crlf    Carriage return char
     * @param array      $servers Servers list
     *
     * @return string|null
     */
    protected static function getServerPart(ConfigFile $cf, $crlf, array $servers)
    {
        if ($cf->getServerCount() === 0) {
            return null;
        }

        $ret = '/* Servers configuration */' . $crlf . '$i = 0;' . $crlf . $crlf;
        foreach ($servers as $id => $server) {
            $ret .= '/* Server: '
                . strtr($cf->getServerName($id) . ' [' . $id . '] ', '*/', '-')
                . '*/' . $crlf
                . '$i++;' . $crlf;
            foreach ($server as $k => $v) {
                $k = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $k);
                $ret .= "\$cfg['Servers'][\$i]['" . $k . "'] = "
                    . (is_array($v) && self::isZeroBasedArray($v)
                        ? self::exportZeroBasedArray($v, $crlf)
                        : var_export($v, true))
                    . ';' . $crlf;
            }
            $ret .= $crlf;
        }
        $ret .= '/* End of servers configuration */' . $crlf . $crlf;

        return $ret;
    }
}
