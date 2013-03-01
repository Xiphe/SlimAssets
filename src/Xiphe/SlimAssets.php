<?php

namespace Xiphe;

class SlimAssets {
	public static $handleAssets = array(
		'css',
		'js',
		'coffee',
		'less'
	);

	public static $compiles = array(
		'coffee' => 'js',
		'less' => 'css'
	);

	public static $html = array(
		'js' => '<script src=":url"></script>',
		'css' => '<link rel="stylesheet" href=":url" />'
	);

	public static $initiated = false;

	public static $assets = array(
		'js' => array(),
		'css' => array()
	);

	public $compact = false;
	public $useCompact = true;
	public $compile = false;
	public $minify = false;
	public $useMinifyed = true;
	public $preferMinifiedForCompacts = true;

	public $basePath = './';
	public $baseUrl = './';
	public $assetPath = 'assets/';
	public $managedPath = 'assets/managed/';
	public $cacheLifetime = -1;

	private $_app = false;

	public function __construct($app = false)
	{
		if (!empty($app)) {
			$this->setApp($app);
		}

		$t = $this;
		$this->_app->configureMode('development', function () use ($t) {
			$t->compile = true;
			$t->minify = true;
			$t->useMinifyed = false;
			$t->compact = true;
			$t->useCompact = false;
			$t->cacheLifetime = 300;
		});
	}

	public function getApp()
	{
		if ($this->_app) {
			return $this->_app;
		}

		throw new SlimAssertsException("No Slim App available");
	}

	public function ensureFileExists($file)
	{
		if (!file_exists($file)) {
			$path = dirname($file);
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
            }

            $handle = @fopen($file, 'w');
            if ($handle) {
                @fclose($handle);
            }
            unset($handle);
        }


        if (!file_exists($file) || !is_writable($file)) {
            throw new SlimAssertsException("File does not exist or is not writable: $filePath");
        }
	}

	public function setApp(\Slim\Slim $app)
	{
		$this->_app = $app;
	}

	public function minifyCss($source)
	{
		$file = basename($source);
		$minFile = $this->getMinifyedName($file);
		$target = "{$this->getmanagedPath()}css/{$minFile}";

		if ($this->shouldBeCompiled($source, $target)) {
			$this->ensureFileExists($target);

			$content = file_get_contents($source);

			$postParams = array(
				'input' => $content
			);

			$query = http_build_query($postParams);

			$ch = curl_init();
			 
			 // setze die URL und andere Optionen
			curl_setopt($ch, CURLOPT_URL, 'http://www.cssminifier.com/raw');
			curl_setopt($ch, CURLOPT_POST, count($postParams));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
			 
			$result = curl_exec($ch);
			curl_close($ch);

			file_put_contents($target, $result);
		}
	}

	public function minifyJs($source)
	{
		$file = basename($source);
		$minFile = $this->getMinifyedName($file);
		$target = "{$this->getmanagedPath()}js/{$minFile}";

		if ($this->shouldBeCompiled($source, $target)) {
			$this->ensureFileExists($target);

			$content = file_get_contents($source);

			$postParams = array(
				'js_code' => $content,
				'output_info' => 'compiled_code',
				'output_format' => 'text',
				'compilation_level' => 'SIMPLE_OPTIMIZATIONS'
			);

			$query = http_build_query($postParams);

			$ch = curl_init();
			 
			 // setze die URL und andere Optionen
			curl_setopt($ch, CURLOPT_URL, 'http://closure-compiler.appspot.com/compile');
			curl_setopt($ch, CURLOPT_POST, count($postParams));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
			 
			$result = curl_exec($ch);
			curl_close($ch);

			file_put_contents($target, $result);
		}
	}

	public function shouldBeCompiled($source, $target)
	{
		return (!file_exists($target) || filemtime($target) < filemtime($source));
	}

	public function compileLess($file)
	{
		$target = "{$this->getmanagedPath()}css/{$file}.css";
		$source = "{$this->getAssetPath()}less/{$file}";

		if ($this->shouldBeCompiled($source, $target)) {
			$this->ensureFileExists($target);
			touch($source);
			$less = new \lessc;
			$less->checkedCompile($source, $target);
		}
	}

	public function compileCoffee($file)
	{
		$target = "{$this->getmanagedPath()}js/{$file}.js";
		$source = "{$this->getAssetPath()}coffee/{$file}";


		if ($this->shouldBeCompiled($source, $target)) {
			$this->ensureFileExists($target);

			$content = file_get_contents($source);
	        $content = \CoffeeScript\Compiler::compile($content);
	        file_put_contents($target, $content);
		}
	}

	public function getMinifyedName($file)
	{
		$file = explode('.', $file);
		array_splice($file, count($file)-1, 0, 'min');
		return implode('.', $file);
	}

	public function getmanagedPath()
	{
		return $this->basePath.$this->managedPath;
	}

	public function getAssetPath()
	{
		return $this->basePath.$this->assetPath;
	}

	public function getAssetUrl($asset)
	{
		$search =  '/'.str_replace('/', '\\/', preg_quote($this->basePath)).'/';
		return preg_replace($search, $this->baseUrl, $asset, 1);
	}

	public function registerAsset($type, $name, $order = 10)
	{
		$file = "{$name}.{$type}";
		$basePath = $this->getAssetPath();
		
		if (array_key_exists($type, self::$compiles)) {
			if ($this->compile) {
				$method = 'compile'.ucfirst($type);
				$this->$method($file);
			}
			$type = self::$compiles[$type];
			$file .= '.'.$type;

			$basePath = $this->getmanagedPath();
		}

		if ($this->minify) {
			$method = 'minify'.ucfirst($type);
			$this->$method("{$basePath}{$type}/{$file}");
		}

		if ($this->useMinifyed) {
			$basePath = $this->getmanagedPath();
			$file = $this->getMinifyedName($file);
		}

		$path = "{$basePath}{$type}/{$file}";

		$this->assets[$type][$order][] = $path;
	}

	public function preferMinified($asset)
	{
		if ($this->preferMinifiedForCompacts) {
			$minName = $this->getMinifyedName(basename($asset));
			$ext = pathinfo($asset, PATHINFO_EXTENSION);
			$minPath = "{$this->getmanagedPath()}{$ext}/{$minName}";

			if (!$this->shouldBeCompiled($asset, $minPath)) {
				$asset = $minPath;
			}
		}
		return $asset;
	}

	public function getCompactFileName($type)
	{	
		$name = '';
		foreach ($this->assets[$type] as $order => $assets) {
			if (!empty($assets)) {
				foreach ($assets as $asset) {
					$asset = $this->preferMinified($asset);

					$name = $asset.filemtime($asset);
				}
			}
		}

		return md5($name);
	}

	public function compact($type)
	{
		$buffer = '';
		$file = "{$this->getCompactFileName($type)}.{$type}";
		$target = "{$this->getmanagedPath()}compact/{$file}";

		if (!file_exists($target)) {
			$this->ensureFileExists($target);

			foreach ($this->assets[$type] as $order => $assets) {
				if (!empty($assets)) {
					foreach ($assets as $asset) {
						$asset = $this->preferMinified($asset);

						$name = $asset.filemtime($asset);
						$buffer .= file_get_contents($asset)."\n";
					}
				}
			}

			file_put_contents($target, trim($buffer));
		} else {
			touch($target);
		}
	}

	public function getCompactUrl($type)
	{
		$file = "{$this->getCompactFileName($type)}.{$type}";
		$asset = "{$this->getmanagedPath()}compact/{$file}";
		touch($asset);
		
		return $this->getAssetUrl($asset);
	}

	public function printAssets($type)
	{
		if ($this->compact) {
			$this->compact($type);
		}

		if ($this->useCompact) {
			echo str_replace(':url', $this->getCompactUrl($type), self::$html[$type]);
		} else {
			foreach ($this->assets[$type] as $order => $assets) {
				if (!empty($assets)) {
					foreach ($assets as $asset) {
						echo str_replace(':url', $this->getAssetUrl($asset), self::$html[$type]);
					}
				}
			}
		}

		return $this;
	}

	public function checkCache()
	{
		if ($this->cacheLifetime < 0) {
			return;
		}

		$flag = "{$this->getmanagedPath()}compact/.slim_assets";
		$this->ensureFileExists($flag);
		if (filemtime($flag) + $this->cacheLifetime > time()) {
			return;
		}

		foreach (glob("{$this->getmanagedPath()}compact/*") as $file) {
			if (filemtime($file) + $this->cacheLifetime < time()) {
				var_dump('delete');
				unlink($file);
			}
		}
		touch($flag);
	}

	public function __call($method, $args)
	{
		if (in_array($method, self::$handleAssets)) {
			$this->registerAsset($method, $args[0]);
			return $this;
		}

		throw new SlimAssertsException("Call to undefined Method $method");
	}

	public function __destruct()
	{
		$this->checkCache();
	}
}

class SlimAssertsException extends \Exception {};