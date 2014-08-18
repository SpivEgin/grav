<?php
namespace Grav\Common;

use \Tracy\Debugger;
use Closure;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

define('CSS_ASSET', true);
define('JS_ASSET', false);

/**
 * Handles Asset management (CSS & JS) and also pipelining (combining into a single file for each asset)
 *
 * Based on stolz/assets (https://github.com/Stolz/Assets) package modified for use with Grav
 *
 * @author RocketTheme
 * @license MIT
 */

class Assets
{
    use GravTrait;

    /** @const Regex to match grav asset shortcodes */
    const GRAV_ASSET_REGEX = '/@(plugin|theme)\/(.*?):(.*)/i';

    /** @const Regex to match CSS and JavaScript files */
    const DEFAULT_REGEX = '/.\.(css|js)$/i';

    /** @const Regex to match CSS files */
    const CSS_REGEX = '/.\.css$/i';

    /** @const Regex to match JavaScript files */
    const JS_REGEX = '/.\.js$/i';

    /** @const Regex to match CSS urls */
    const CSS_URL_REGEX = '{url\([\'\"]?((?!http|//).*?)[\'\"]?\)}';

    /** @const Regex to match CSS sourcemap comments */
    const CSS_SOURCEMAP_REGEX = '{\/\*# (.*) \*\/}';

    /** @const Regex to match CSS import content */
    const CSS_IMPORT_REGEX = '{@import(.*);}';


    /**
     * Closure used by the pipeline to fetch assets.
     *
     * Useful when file_get_contents() function is not available in your PHP
     * instalation or when you want to apply any kind of preprocessing to
     * your assets before they get pipelined.
     *
     * The closure will receive as the only parameter a string with the path/URL of the asset and
     * it should return the content of the asset file as a string.
     * @var Closure
     */
    protected $fetch_command;

    // Configuration toggles to enable/disable the pipelining feature
    protected $css_pipeline = false;
    protected $js_pipeline = false;

    // The asset holding arrays
    protected $collections = array();
    protected $css = array();
    protected $js = array();

    // Some configuration variables
    protected $config;
    protected $theme_url;
    protected $base_url;

    // Default values for pipeline settings
    protected $css_minify = true;
    protected $css_rewrite = true;
    protected $js_minify = true;

    // Arrays to hold assets that should NOT be pipelined
    protected $css_no_pipeline = array();
    protected $js_no_pipeline = array();


    public function __construct(array $options = array())
    {
        // Forward config options
        if($options)
            $this->config((array)$options);
    }

    /**
     * Initialization called in the Grav lifecycle to initialize the Assets with appropriate configuration
     */
    public function init()
    {
        /** @var Config $config */
        $config = self::$grav['config'];
        $base_url = trim($config->get('system.base_url_relative'));
        $theme = $config->get('system.pages.theme');
        $asset_config = (array)$config->get('system.assets');

        $this->config($asset_config);
        $this->base_url = $base_url . '/';
        $this->theme_url = $base_url . '/' . USER_PATH . basename(THEMES_DIR) .'/'. $theme;

    }

    /**
     * Set up configuration options.
     *
     * All the class properties except 'js' and 'css' are accepted here.
     * Also, an extra option 'autoload' may be passed containing an array of
     * assets and/or collections that will be automatically added on startup.
     *
     * @param  array $options Configurable options.
     * @return $this
     * @throws \Exception
     */
    public function config(array $config)
    {
        // Set pipeline modes
        if(isset($config['css_pipeline']))
            $this->css_pipeline = $config['css_pipeline'];

        if(isset($config['js_pipeline']))
            $this->js_pipeline = $config['js_pipeline'];

        // Pipeline requires public dir
        if(($this->js_pipeline || $this->css_pipeline) && ! is_dir(ASSETS_DIR))
            throw new \Exception('Assets: Public dir not found');

        // Set custom pipeline fetch command
        if(isset($config['fetch_command']) and ($config['fetch_command'] instanceof Closure))
            $this->fetch_command = $config['fetch_command'];

        // Set CSS Minify state
        if(isset($config['css_minify']))
            $this->css_minify = $config['css_minify'];

        if(isset($config['css_rewrite']))
            $this->css_rewrite = $config['css_rewrite'];

        // Set JS Minify state
        if(isset($config['js_minify']))
            $this->js_minify = $config['js_minify'];

        // Set collections
        if(isset($config['collections']) and is_array($config['collections']))
            $this->collections = $config['collections'];

        // Autoload assets
        if(isset($config['autoload']) and is_array($config['autoload']))
        {
            foreach($config['autoload'] as $asset)
            {
                $this->add($asset);
            }
        }

        return $this;
    }

    /**
     * Add an asset or a collection of assets.
     *
     * It automatically detects the asset type (JavaScript, CSS or collection).
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @param  int     $priority the priority, bigger comes first
     * @param  bool    $pipeline false if this should not be pipelined
     * @return $this
     */
    public function add($asset, $priority = 10, $pipeline = true)
    {
        // More than one asset
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->add($a, $priority, $pipeline);
        }
        // Collection
        elseif(isset($this->collections[$asset]))
        {
            $this->add($this->collections[$asset], $priority, $pipeline);
        }
        else
        {
            // JavaScript or CSS
            $info = pathinfo($asset);
            if(isset($info['extension']))
            {
                $ext = strtolower($info['extension']);
                if($ext === 'css')
                    $this->addCss($asset, $priority, $pipeline);
                elseif($ext === 'js')
                    $this->addJs($asset, $priority, $pipeline);
            }
        }

        return $this;
    }

    /**
     * Add a CSS asset.
     *
     * It checks for duplicates.
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @param  int     $priority the priority, bigger comes first
     * @param  bool    $pipeline false if this should not be pipelined
     * @return $this
     */
    public function addCss($asset, $priority = 10, $pipeline = true)
    {
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->addCss($a, $priority, $pipeline);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset);

        if( ! in_array($asset, $this->css))
            $this->css[] = ['asset'=>$asset, 'priority'=>$priority, 'pipeline'=>$pipeline];

        return $this;
    }

    /**
     * Add a JavaScript asset.
     *
     * It checks for duplicates.
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @param  int     $priority the priority, bigger comes first
     * @param  bool    $pipeline false if this should not be pipelined
     * @return $this
     */
    public function addJs($asset, $priority = 10, $pipeline = true)
    {
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->addJs($a, $priority, $pipeline);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset);

        if( ! in_array($asset, $this->js))
            $this->js[] = ['asset'=>$asset, 'priority'=>$priority, 'pipeline'=>$pipeline];

        return $this;
    }

    /**
     * Build the CSS link tags.
     *
     * @return string
     */
    public function css()
    {
        if( ! $this->css)
            return null;

        // Sort array by priorities (larger priority first)
        usort($this->css, function ($a, $b) {return $a['priority'] - $b['priority'];});
        $this->css = array_reverse($this->css);



        $output = '';
        if($this->css_pipeline) {
            $output .= '<link type="text/css" rel="stylesheet" href="'.$this->pipeline(CSS_ASSET).'" />'."\n";

            foreach ($this->css_no_pipeline as $file) {
                $output .= '<link type="text/css" rel="stylesheet" href="'.$file['asset'].'" />'."\n";
            }
            return $output;
        }


        foreach($this->css as $file)
            $output .= '<link type="text/css" rel="stylesheet" href="'.$file['asset'].'" />'."\n";

        return $output;
    }

    /**
     * Build the JavaScript script tags.
     *
     * @return string
     */
    public function js()
    {
        if( ! $this->js)
            return null;

        // Sort array by priorities (larger priority first)
        usort($this->js, function ($a, $b) {return $a['priority'] - $b['priority'];});
        $this->js = array_reverse($this->js);

        $output = '';
        if($this->js_pipeline) {
            $output .= '<script type="text/javascript" src="'.$this->pipeline(JS_ASSET).'"></script>'."\n";
            foreach ($this->js_no_pipeline as $file) {
                $output .= '<script type="text/javascript" src="'.$file['asset'].'"></script>'."\n";
            }
            return $output;
        }


        foreach($this->js as $file)
            $output .= '<script type="text/javascript" src="'.$file['asset'].'"></script>'."\n";

        return $output;
    }

    /**
     * Add/replace collection.
     *
     * @param  string  $collectionName
     * @param  array   $assets
     * @return $this
     */
    public function registerCollection($collectionName, Array $assets)
    {
        $this->collections[$collectionName] = $assets;

        return $this;
    }

    /**
     * Reset all assets.
     *
     * @return $this
     */
    public function reset()
    {
        return $this->resetCss()->resetJs();
    }

    /**
     * Reset CSS assets.
     *
     * @return $this
     */
    public function resetCss()
    {
        $this->css = array();

        return $this;
    }

    /**
     * Reset JavaScript assets.
     *
     * @return $this
     */
    public function resetJs()
    {
        $this->js = array();

        return $this;
    }

    /**
     * Minifiy and concatenate CSS / JS files.
     *
     * @return string
     */
    protected function pipeline($css = true)
    {
        /** @var Cache $cache */
        $cache = self::$grav['cache'];
        $key = '?'.$cache->getKey();

        if ($css) {
            $file = md5(json_encode($this->css) . $this->js_minify . $this->css_minify . $this->css_rewrite) . '.css';
            foreach ($this->css as $id => $asset) {
                if (!$asset['pipeline']) {
                    $this->css_no_pipeline[] = $asset;
                    unset($this->css[$id]);
                }
            }
        } else {
            $file = md5(json_encode($this->js) . $this->js_minify . $this->css_minify . $this->css_rewrite) . '.js';
            foreach ($this->js as $id => $asset) {
                if (!$asset['pipeline']) {
                    $this->js_no_pipeline[] = $asset;
                    unset($this->js[$id]);
                }
            }
        }

        $relative_path = "{$this->base_url}".basename(ASSETS_DIR)."/{$file}";
        $absolute_path = ASSETS_DIR.$file;

        // If pipeline exist return it
        if(file_exists($absolute_path))
            return $relative_path . $key;

        // Concatenate files
        if ($css) {
            $buffer = $this->gatherLinks($this->css, CSS_ASSET);
            if ($this->css_minify) {
                $min = new \CSSmin();
                $buffer = $min->run($buffer);
            }
        } else {
            $buffer = $this->gatherLinks($this->js, JS_ASSET);
            if ($this->js_minify) {
                $buffer = \JSMin::minify($buffer);
            }
        }

        // Write file
        file_put_contents($absolute_path, $buffer);

        return $relative_path . $key;
    }

    /**
     * Download and concatenate the content of several links.
     *
     * @param  array  $links
     * @return string
     */
    protected function gatherLinks(array $links, $css = true)
    {


        $buffer = '';
        $local = true;

        foreach($links as $asset)
        {
            $link = $asset['asset'];
            $relative_path = $link;

            if($this->isRemoteLink($link)) {
                $local = false;
                if('//' === substr($link, 0, 2))
                    $link = 'http:' . $link;
            } else {
                // Fix to remove relative dir if grav is in one
                if (($this->base_url != '/') && (strpos($this->base_url, $link) == 0)) {
                    $relative_path = str_replace($this->base_url, '/', $link);
                }

                $relative_dir = dirname ($relative_path);
                $link = ROOT_DIR . $relative_path;
            }

            $file = ($this->fetch_command instanceof Closure) ? $this->fetch_command->__invoke($link) : file_get_contents($link);

            // If this is CSS + the file is local + rewrite enabled
            if ($css && $local && $this->css_rewrite) {
                $file = $this->cssRewrite($file, $relative_dir);
            }

            $buffer .= $file;
        }

        // Pull out @imports and move to top
        if ($css) {
            $buffer = $this->moveImports($buffer);
        }

        return $buffer;
    }

    /**
     * Moves @import statements to the top of the file per the CSS specification
     *
     * @param  string $file the file containing the combined CSS files
     * @return string       the modified file with any @imports at the top of the file
     */
    protected function moveImports($file)
    {
        $this->imports = array();

        $file = preg_replace_callback(self::CSS_IMPORT_REGEX,
            function($matches) {
                $this->imports[] = $matches[0];
                return '';
            },
            $file
        );

        return implode("\n", $this->imports) . "\n\n" . $file;
    }

    /**
     * Finds relative CSS urls() and rewrites the URL with an absolute one
     * @param  string $file          the css source file
     * @param  string $relative_path relative path to the css file
     * @return [type]                [description]
     */
    protected function cssRewrite($file, $relative_path)
    {
        // Strip any sourcemap comments
        $file = preg_replace(self::CSS_SOURCEMAP_REGEX, '', $file);

        // Find any css url() elements, grab the URLs and calculate an absolute path
        // Then replace the old url with the new one
        $file = preg_replace_callback(self::CSS_URL_REGEX,
                function($matches) use ($relative_path) {

                    $old_url = $matches[1];
                    $newpath = array();
                    $paths = explode('/', $old_url);

                    foreach ($paths as $path) {
                        if ($path == '..') {
                            $relative_path = dirname($relative_path);
                        } else {
                            $newpath[] = $path;
                        }
                    }

                    $new_url = rtrim($this->base_url,'/') . $relative_path . '/' . implode('/', $newpath);

                    return str_replace($old_url, $new_url, $matches[0]);
                },
                $file
        );

        return $file;
    }

    /**
     * Build local links including grav asset shortcodes
     *
     * @param  string $asset the asset string reference
     * @return string        the final link url to the asset
     */
    protected function buildLocalLink($asset)
    {

        $matches = $this->assetIsGravPackage($asset);
        $base_url = $this->base_url;


        if($matches === false)
            return $base_url . $asset;

        if($matches[1] == 'theme') {
            return $this->theme_url . '/' . $matches[2] . '/' . $matches[3];
        } elseif ($matches[1] == 'plugin') {
            return $base_url . 'user/plugins/' . $matches[2] . '/' . $matches[3];
        } else {
            return $base_url . $asset;
        }


    }

    /**
     * Determines if an asset contains a valid grav asset shortcode
     * Currently supported formats are:
     * @plugin/plugin_name/directories:assetname.js|css
     * @theme/directories:assetname.js|css
     *
     * @param  string $asset    the asset string reference
     * @return array|bool    if there are matches, those regex matches are returned, else return false
     */
    protected function assetIsGravPackage($asset)
    {
        if(preg_match(self::GRAV_ASSET_REGEX, $asset, $matches))
            return $matches;
        return false;
    }



    /**
     * Determine whether a link is local or remote.
     *
     * Undestands both "http://" and "https://" as well as protocol agnostic links "//"
     *
     * @param  string $link
     * @return bool
     */
    protected function isRemoteLink($link)
    {
        return ('http://' === substr($link, 0, 7) or 'https://' === substr($link, 0, 8) or '//' === substr($link, 0, 2));
    }

    /**
     * Get all CSS assets already added.
     *
     * @return array
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * Get all JavaScript assets already added.
     *
     * @return array
     */
    public function getJs()
    {
        return $this->js;
    }

    /**
     * Add all assets matching $pattern within $directory.
     *
     * @param  string $directory Relative to $this->public_dir
     * @param  string $pattern (regex)
     * @return $this
     * @throws Exception
     */
    public function addDir($directory, $pattern = self::DEFAULT_REGEX)
    {
        // Check if public_dir exists
        if( ! is_dir(ASSETS_DIR))
            throw new Exception('Assets: Public dir not found');

        // Get files
        $files = $this->rglob(ASSETS_DIR . DIRECTORY_SEPARATOR . $directory, $pattern, ASSETS_DIR);

        // No luck? Nothing to do
        if( ! $files)
            return $this;

        // Add CSS files
        if($pattern === self::CSS_REGEX)
        {
            $this->css = array_unique(array_merge($this->css, $files));
            return $this;
        }

        // Add JavaScript files
        if($pattern === self::JS_REGEX)
        {
            $this->js = array_unique(array_merge($this->js, $files));
            return $this;
        }

        // Unknown pattern. We must poll to know the extension :(
        foreach($files as $asset)
        {
            $info = pathinfo($asset);
            if(isset($info['extension']))
            {
                $ext = strtolower($info['extension']);
                if($ext === 'css' and ! in_array($asset, $this->css))
                    $this->css[] = $asset;
                elseif($ext === 'js' and ! in_array($asset, $this->js))
                    $this->js[] = $asset;
            }
        }

        return $this;
    }

    /**
     * Add all CSS assets within $directory (relative to public dir).
     *
     * @param  string $directory Relative to $this->public_dir
     * @return $this
     */
    public function addDirCss($directory)
    {
        return $this->addDir($directory, self::CSS_REGEX);
    }

    /**
     * Add all JavaScript assets within $directory.
     *
     * @param  string $directory Relative to $this->public_dir
     * @return $this
     */
    public function addDirJs($directory)
    {
        return $this->addDir($directory, self::JS_REGEX);
    }

    /**
     * Recursively get files matching $pattern within $directory.
     *
     * @param  string $directory
     * @param  string $pattern (regex)
     * @param  string $ltrim Will be trimed from the left of the file path
     * @return array
     */
    protected function rglob($directory, $pattern, $ltrim = null)
    {
        $iterator = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)), $pattern);
        $offset = strlen($ltrim);
        $files = array();

        foreach($iterator as $file)
            $files[] = substr($file->getPathname(), $offset);

        return $files;
    }

    /**
     * @param $a
     * @param $b
     * @return mixed
     */
    protected function priorityCompare($a, $b)
    {
        return $a ['priority'] - $b ['priority'];
    }

    public function __toString() {
        return '';
    }

}
