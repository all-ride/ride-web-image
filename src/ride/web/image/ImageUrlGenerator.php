<?php

namespace ride\web\image;

use ride\library\dependency\DependencyInjector;
use ride\library\http\Response;
use ride\library\image\exception\ImageException;
use ride\library\image\Dimension;
use ride\library\image\ImageUrlGenerator as LibImageUrlGenerator;
use ride\library\system\file\browser\FileBrowser;
use ride\library\system\file\File;

/**
 * URL generator for images.
 */
class ImageUrlGenerator implements LibImageUrlGenerator {

    /**
     * Path in the public directory to cache processed images
     * @var string
     */
    const DEFAULT_PATH = 'cache/img';

    /**
     * Instance of the file browser
     * @var ride\library\system\file\browser\FileBrowser
     */
    private $fileBrowser;

    /**
     * Instance of the dependency injector
     * @var ride\library\dependency\DependencyInjector
     */
    private $dependencyInjector;

    /**
     * Base URL for the images
     * @var string
     */
    private $baseUrl;

    /**
     * Absolute path of the public directory
     * @var string
     */
    private $publicPath;

    /**
     * The path for the processed images
     * @var ride\library\filesystem\File
     */
    private $path;

    /**
     * Constructs a image URL generator
     * @param ride\library\system\file\browser\FileBrowser $fileBrowser
     * @param string $path Relative path in the public directory to save the
     * processed images
     * @return null
     */
    public function __construct(FileBrowser $fileBrowser, DependencyInjector $dependencyInjector, $baseUrl, $path = null) {
        $this->fileBrowser = $fileBrowser;
        $this->dependencyInjector = $dependencyInjector;
        $this->baseUrl = $baseUrl;
        $this->publicPath = $fileBrowser->getPublicDirectory()->getAbsolutePath();

        $this->setPath($path);
    }

    /**
     * Sets the path to save the processed images
     * @param string $path Relative path in the public directory
     * @return null
     */
    public function setPath($path) {
        if ($path == null) {
            $path = self::DEFAULT_PATH;
        }

        $this->path = $this->fileBrowser->getPublicDirectory()->getChild($path);
    }

    /**
     * Gets the cache directory
     * @return ride\library\system\file\File
     */
    public function getCacheDirectory() {
        return $this->path;
    }

    /**
     * Generates a URL for the provided image. Images will be cached in the
     * public folder for fast access
     * @param string $image Relative path of the image
     * @param string $thumbnailer Name of the thumbnailer to use
     * @param int $width Width for the thumbnailer
     * @param int $height Height for the thumbnailer
     * @return null
     */
    public function generateUrl($image, $thumbnailer = null, $width = 0, $height = 0) {
        $useThumbnailer = $thumbnailer && ($width > 0 || $height > 0);

        if (strlen($image) > 7 && substr($image, 0, 7) == 'http://' || substr($image, 0, 8) == 'https://') {
            // image is a URL
            if (!$useThumbnailer) {
                return $image;
            }

            $file = $this->getCacheFile($image, $thumbnailer, $width, $height);
            if (!$file->exists()) {
                $httpClient = $this->dependencyInjector->get('ride\\library\\http\\client\\Client');
                $response = $httpClient->get($image);
                if ($response->getStatusCode() != Response::STATUS_CODE_OK) {
                    throw new ImageException('Could not generate URL for ' . $image . ': file not found');
                }

                $file->write($response->getBody());

                $this->applyThumbnailer($file, $thumbnailer, $width, $height);
            }
        } else {
            // image is a local file
            $source = $this->lookupFile($image);

            $isPublicFile = strpos($source->getAbsolutePath(), $this->publicPath) === 0;
            if (!$useThumbnailer && $isPublicFile) {
                $file = $source;
            } else {
                $file = $this->getCacheFile($source->getPath(), $thumbnailer, $width, $height);

                if (!$file->exists() || $source->getModificationTime() > $file->getModificationTime()) {
                    $source->copy($file);

                    if ($useThumbnailer) {
                        $this->applyThumbnailer($file, $thumbnailer, $width, $height);
                    }
                }
            }
        }

        // make the resulting image relative to the public directory
        $image = str_replace($this->publicPath, '', $file->getAbsolutePath());

        // return the full URL
        return $this->baseUrl . $image;
    }

    /**
     * Applies the provided thumbnailer to the provided file
     * @param \ride\library\system\file\File $file File of the source image
     * @param string $thumbnailer Name of the thumbnailer
     * @param integer $width Width of the resulting image
     * @param integer $height Height of the resulting image
     * @return null
     */
    protected function applyThumbnailer(File $file, $thumbnailer, $width, $height) {
        $thumbnailer = $this->dependencyInjector->get('ride\\library\\image\\thumbnail\\Thumbnailer', $thumbnailer);
        $imageFactory = $this->dependencyInjector->get('ride\\library\\image\\io\\ImageFactory');

        $image = $imageFactory->read($file);

        $thumbnail = $thumbnailer->getThumbnail($image, new Dimension($width, $height));
        if ($image !== $thumbnail) {
            // thumbnail generated, write to cache file
            $imageFactory->write($file, $thumbnail);
        }
    }

    /**
     * Gets the cache file for the image source
     * @param string $source Source to get a cache file for (local path or URL)
     * @param string $thumbnailer Name of the thumbnailer
     * @param integer $width The width of the cached image
     * @param integer $height The height of the cached image
     * @return ride\library\system\file\File unique name for a source file, in
     * the cache directory, with the thumbnailer, width and height encoded into
     */
    protected function getCacheFile($source, $thumbnailer = null, $width = 0, $height = 0) {
        $fileName = md5(
            $source .
            '-thumbnailer=' . $thumbnailer .
            '-width=' . $width .
            '-height=' . $height
        );

        $positionSlash = strrpos($source, '/');
        if ($positionSlash !== false) {
            $name = substr($source, $positionSlash + 1);
        } else {
            $name = $source;
        }

        $fileName .= '-' . $name;

        return $this->path->getChild($fileName);
    }

    /**
     * Gets the local file for the provided file path
     * @param string $source Path of a local file
     * @return \ride\library\system\file\File
     * @throws \ride\library\image\exception\ImageException when the file could
     * not be found
     */
    protected function lookupFile($source) {
        // image is a local file
        $file = $this->fileBrowser->getFileSystem()->getFile($source);

        if ($file->isAbsolute()) {
            return $file;
        }

        // relative file, get the image from the file browser
        $file = $this->fileBrowser->getPublicFile($source);
        if ($file) {
            return $file;
        }

        $file = $this->fileBrowser->getFile($source);
        if ($file) {
            return $file;
        }

        throw new ImageException('Could not generate URL for ' . $source . ': file not found');
    }

}
