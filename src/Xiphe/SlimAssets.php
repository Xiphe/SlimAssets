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

	public static $images = array(
		'gif',
		'jpg',
		'png'
	);

	public static $html = array(
		'js' => '<script src=":uri"></script>',
		'css' => '<link rel="stylesheet" href=":uri" />',
		'img' => '<img src=":uri" :alt/>'
	);

	public static $initiated = false;

	public $assets = array(
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
      throw new SlimAssertsException("File does not exist or is not writable: $file");
    }
	}

	public function setApp(\Slim\Slim $app)
	{
		$this->_app = $app;
	}

	public function minifyCss($source, $target)
	{
		if ($this->shouldBeCompiled($source, $target)) {
			$this->ensureFileExists($target);

			$content = file_get_contents($source);

			$postParams = array(
				'input' => $content
			);

			$query = http_build_query($postParams);

			$ch = curl_init();

			// setze die URL und andere Optionen
			curl_setopt($ch, CURLOPT_URL, 'http://cssminifier.com/raw');
			curl_setopt($ch, CURLOPT_POST, count($postParams));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

			$result = curl_exec($ch);
			curl_close($ch);

			file_put_contents($target, $result);
		}
	}

	public function minifyJs($source, $target)
	{
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
		$target = "{$this->getManagedPath()}css/{$file}.css";
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
		$target = "{$this->getManagedPath()}js/{$file}.js";
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

	public function getManagedPath()
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
		$managed = false;

		if (array_key_exists($type, self::$compiles)) {
			if ($this->compile) {
				$method = 'compile'.ucfirst($type);
				$this->$method($file);
			}
			$type = self::$compiles[$type];
			$file .= '.'.$type;

			$basePath = $this->getManagedPath();
			$managed = true;
		}

		if ($this->minify) {
			$method = 'minify'.ucfirst($type);
			$minFile = $this->getMinifyedName($file);
			$source = ($managed ? $this->getManagedPath() : $this->getAssetPath());
			$source = "{$source}{$type}/{$file}";

			$target = "{$this->getManagedPath()}{$type}/{$minFile}";
			$this->$method($source, $target);
		}

		if ($this->useMinifyed) {
			$basePath = $this->getManagedPath();
			$file = $this->getMinifyedName($file);
			$managed = true;
		}

		$path = "{$basePath}{$type}/{$file}";

		$this->assets[$type][$order][] = $path;
	}

	public function preferMinified($asset)
	{
		if ($this->preferMinifiedForCompacts) {
			$ext = pathinfo($asset, PATHINFO_EXTENSION);

			$name = str_replace($this->getManagedPath().$ext.'/', '', $asset);
			if ($name === $asset) {
				$name = str_replace($this->getAssetPath().$ext.'/', '', $asset);
			}
			$minName = $this->getMinifyedName($name);
			$minPath = "{$this->getManagedPath()}{$ext}/{$minName}";

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

					$name .= $asset.filemtime($asset);
				}
			}
		}

		return md5($name);
	}

	public function compact($type)
	{
		$buffer = '';
		$file = "{$this->getCompactFileName($type)}.{$type}";
		$target = "{$this->getManagedPath()}compact/{$file}";

		if (!file_exists($target)) {
			$this->ensureFileExists($target);

			ksort($this->assets[$type]);

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
		$asset = "{$this->getManagedPath()}compact/{$file}";
		touch($asset);
		
		return $this->getAssetUrl($asset);
	}

	public function printAssets($type)
	{
		if ($this->compact) {
			$this->compact($type);
		}

		if ($this->useCompact) {
			echo str_replace(':uri', $this->getCompactUrl($type), self::$html[$type]);
		} else {

			ksort($this->assets[$type]);

			foreach ($this->assets[$type] as $order => $assets) {
				if (!empty($assets)) {
					foreach ($assets as $asset) {
						echo str_replace(':uri', $this->getAssetUrl($asset), self::$html[$type]);
					}
				}
			}
		}

		return $this;
	}

	public function printImageTag($type, $name, $alt = null)
	{
		$image = "{$this->getAssetPath()}img/{$name}.{$type}";

		$tag = str_replace(':uri', $this->getAssetUrl($image), self::$html['img']);

		$alt = (!empty($alt) ? "alt=\"{$alt}\" " : '');

		echo str_replace(':alt', $alt, $tag);
	}

	public function checkCache()
	{
		if ($this->cacheLifetime < 0) {
			return;
		}

		$flag = "{$this->getManagedPath()}compact/.slim_assets";
		$this->ensureFileExists($flag);
		if (filemtime($flag) + $this->cacheLifetime > time()) {
			return;
		}

		foreach (glob("{$this->getManagedPath()}compact/*") as $file) {
			if (filemtime($file) + $this->cacheLifetime < time()) {
				unlink($file);
			}
		}
		touch($flag);
	}

	public function __call($method, $args)
	{
		if (in_array($method, self::$handleAssets)) {
			array_splice($args, 0, 0, $method);
			call_user_func_array(array($this, 'registerAsset'), $args);
			return $this;
		}

		if (in_array($method, self::$images)) {
			array_splice($args, 0, 0, $method);
			call_user_func_array(array($this, 'printImageTag'), $args);
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
