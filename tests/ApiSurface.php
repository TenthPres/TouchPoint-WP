<?php
/**
 * Builds a description of the plugin's public API, for comparison against the committed snapshot.
 *
 * The "public API" is everything a theme, another plugin, or a site's custom code could depend on:
 *  - public and protected classes, interfaces, and traits in src/TouchPoint-WP (with their constants, properties,
 *    and methods, including signatures and types),
 *  - the names of WordPress filters and actions the plugin provides (apply_filters / do_action), and
 *  - the template files that themes may override.
 */

namespace tp\TouchPointWP\Tests;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

class ApiSurface
{
    public const SNAPSHOT_PATH = __DIR__ . '/api-surface.json';

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return array The complete API description.
     */
    public static function build(): array
    {
        return [
            'classes'   => self::classes(),
            'hooks'     => self::hooks(),
            'templates' => self::templates(),
        ];
    }

    /**
     * @return string[] Absolute paths of PHP files in src/TouchPoint-WP, sorted.
     */
    private static function sourceFiles(): array
    {
        $dir   = self::root() . '/src/TouchPoint-WP';
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function rel(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(self::root()) + 1));
    }

    private static function classes(): array
    {
        $before = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
        foreach (self::sourceFiles() as $file) {
            require_once $file;
        }
        $after = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());

        $out = [];
        foreach (array_diff($after, $before) as $name) {
            $rc = new ReflectionClass($name);
            if ($rc->isInternal() || strpos($rc->getFileName(), self::root() . DIRECTORY_SEPARATOR . 'src') !== 0) {
                continue;
            }
            $out[$name] = self::describeClass($rc);
        }
        ksort($out);
        return $out;
    }

    private static function describeClass(ReflectionClass $rc): array
    {
        $kind = $rc->isInterface() ? 'interface' : ($rc->isTrait() ? 'trait' : 'class');
        $d    = [
            'kind'       => $kind,
            'file'       => self::rel($rc->getFileName()),
            'abstract'   => $kind === 'class' && $rc->isAbstract(),
            'final'      => $rc->isFinal(),
            'parent'     => $rc->getParentClass() ? $rc->getParentClass()->getName() : null,
            'interfaces' => $rc->getInterfaceNames(),
            'traits'     => $rc->getTraitNames(),
        ];
        sort($d['interfaces']);
        sort($d['traits']);

        $d['constants'] = [];
        foreach ($rc->getReflectionConstants() as $c) {
            if ($c->isPrivate() || $c->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $d['constants'][$c->getName()] = [
                'visibility' => $c->isProtected() ? 'protected' : 'public',
                'final'      => method_exists($c, 'isFinal') ? $c->isFinal() : false,
                'value'      => self::normalizeValue($c->getValue()),
            ];
        }
        ksort($d['constants']);

        $defaults        = $rc->getDefaultProperties();
        $d['properties'] = [];
        foreach ($rc->getProperties() as $p) {
            if ($p->isPrivate() || $p->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $d['properties'][$p->getName()] = self::describeProperty($p, $defaults);
        }
        ksort($d['properties']);

        $d['methods'] = [];
        foreach ($rc->getMethods() as $m) {
            if ($m->isPrivate() || $m->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $d['methods'][$m->getName()] = self::describeMethod($m);
        }
        ksort($d['methods']);

        return $d;
    }

    private static function describeProperty(ReflectionProperty $p, array $defaults): array
    {
        return [
            'visibility' => $p->isProtected() ? 'protected' : 'public',
            'static'     => $p->isStatic(),
            'type'       => $p->hasType() ? (string)$p->getType() : null,
            'default'    => array_key_exists($p->getName(), $defaults) ? self::normalizeValue($defaults[$p->getName()]) : null,
        ];
    }

    private static function describeMethod(ReflectionMethod $m): array
    {
        $params = [];
        foreach ($m->getParameters() as $param) {
            $params[] = self::describeParam($param);
        }
        return [
            'visibility' => $m->isProtected() ? 'protected' : 'public',
            'static'     => $m->isStatic(),
            'abstract'   => $m->isAbstract(),
            'final'      => $m->isFinal(),
            'returnType' => $m->hasReturnType() ? (string)$m->getReturnType() : null,
            'returnsRef' => $m->returnsReference(),
            'parameters' => $params,
        ];
    }

    private static function describeParam(ReflectionParameter $p): array
    {
        $default = null;
        if ($p->isDefaultValueAvailable()) {
            $default = $p->isDefaultValueConstant()
                ? 'const:' . $p->getDefaultValueConstantName()
                : self::normalizeValue($p->getDefaultValue());
        }
        return [
            'name'     => $p->getName(),
            'type'     => $p->hasType() ? (string)$p->getType() : null,
            'optional' => $p->isOptional(),
            'default'  => $default,
            'byRef'    => $p->isPassedByReference(),
            'variadic' => $p->isVariadic(),
        ];
    }

    /**
     * Makes a value JSON-stable.
     *
     * @param mixed $v
     *
     * @return mixed
     */
    private static function normalizeValue($v)
    {
        if (is_array($v)) {
            return array_map([self::class, 'normalizeValue'], $v);
        }
        if (is_float($v) && !is_finite($v)) {
            return 'float:' . var_export($v, true); // JSON can't represent INF or NAN.
        }
        if (is_object($v)) {
            return 'object:' . get_class($v);
        }
        return $v;
    }

    /**
     * @return string[] Names (as written in the source) of filters and actions the plugin offers, sorted.
     */
    private static function hooks(): array
    {
        $files   = self::sourceFiles();
        $files[] = self::root() . '/touchpoint-wp.php';
        foreach (self::templateFiles() as $t) {
            $files[] = $t;
        }
        $found = [];
        foreach (array_unique($files) as $file) {
            $src = file_get_contents($file);
            // Match the first argument of apply_filters / do_action, up to the first top-level comma.
            if (preg_match_all('/\b(apply_filters|do_action)(?:_ref_array)?\s*\(\s*((?:[^,()]|\([^()]*\))+)/', $src, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $name = preg_replace('/\s+/', '', $m[2]);
                    $name = str_replace('"', "'", $name);
                    // Only hooks the plugin owns; WordPress core and third-party hooks it merely calls aren't its API.
                    if (strpos($name, 'HOOK_PREFIX') !== false || strpos($name, 'TOKEN') !== false) {
                        $found[$m[1] . ':' . $name] = true;
                    }
                }
            }
        }
        $names = array_keys($found);
        sort($names);
        return $names;
    }

    /**
     * @return string[] Absolute paths of everything under src/templates.
     */
    private static function templateFiles(): array
    {
        $dir = self::root() . '/src/templates';
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @return string[] Template files (relative to src/templates) that themes can override, sorted.
     */
    private static function templates(): array
    {
        $prefix = self::root() . '/src/templates/';
        $prefix = str_replace('\\', '/', $prefix);
        $out    = [];
        foreach (self::templateFiles() as $f) {
            $out[] = substr(str_replace('\\', '/', $f), strlen($prefix));
        }
        sort($out);
        return $out;
    }

    public static function encode(array $api): string
    {
        return json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }
}
