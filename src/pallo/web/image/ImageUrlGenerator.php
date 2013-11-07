<?php

namespace pallo\web\image;

use pallo\library\dependency\DependencyInjector;
use pallo\library\image\exception\ImageException;
use pallo\library\image\Dimension;
use pallo\library\image\ImageUrlGenerator as LibImageUrlGenerator;
use pallo\library\system\file\browser\FileBrowser;
use pallo\library\system\file\File;

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
     * @var pallo\library\system\file\browser\FileBrowser
     */
    private $fileBrowser;

    /**
     * Instance of the dependency injector
     * @var pallo\library\dependency\DependencyInjector
     */
    private $dependencyInjector;

    /**
     * Base URL for the images
     * @var string
     */
    private $baseUrl;

    /**
     * The path for the processed images
     * @var pallo\library\filesystem\File
     */
    private $path;

    /**
     * Constructs a image URL generator
     * @param pallo\library\system\file\browser\FileBrowser $fileBrowser
     * @param string $path Relative path in the public directory to save the
     * processed images
     * @return null
     */
    public function __construct(FileBrowser $fileBrowser, DependencyInjector $dependencyInjector, $baseUrl, $path = null) {
        $this->fileBrowser = $fileBrowser;
        $this->dependencyInjector = $dependencyInjector;
        $this->baseUrl = $baseUrl;

        if ($path == null) {
            $path = self::DEFAULT_PATH;
        }

        $this->path = $fileBrowser->getPublicDirectory()->getChild($path);
    }

    /**
     * Gets the cache directory
     * @return pallo\library\system\file\File
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
        // ignore urls
        if (strlen($image) > 7 && substr($image, 0, 7) == 'http://' || substr($image, 0, 8) == 'https://') {
            return $image;
        }

        $fileSource = $this->fileBrowser->getFileSystem()->getFile($image);

        if (!$fileSource->isAbsolute()) {
            // no absolute file, get the image from Zibo
            $fileSource = $this->fileBrowser->getPublicFile($image);
            if (!$fileSource) {
                $fileSource = $this->fileBrowser->getFile($image);
            }

            if (!$fileSource) {
                throw new ImageException('Could not generate URL for ' . $image . ': file not found');
            }
        }

        $fileDestination = $this->getCacheFile($fileSource, $thumbnailer, $width, $height);

        if (!$fileDestination->exists() || $fileSource->getModificationTime() > $fileDestination->getModificationTime()) {
            if ($thumbnailer && ($width > 0 || $height > 0)) {
                $thumbnailer = $this->dependencyInjector->get('pallo\\library\\image\\thumbnail\\Thumbnailer', $thumbnailer);
                $imageIO = $this->dependencyInjector->get('pallo\\library\\image\\io\\ImageIO', $fileSource->getExtension());

                $image = $imageIO->read($fileSource);

                $thumbnail = $thumbnailer->getThumbnail($image, new Dimension($width, $height));
                if ($image === $thumbnail) {
                    $fileSource->copy($fileDestination);
                } else {
                    $imageIO->write($fileDestination, $thumbnail->getResource());
                }
            } else {
                $fileSource->copy($fileDestination);
            }
        }

        // make the image relative to the public directory
        $image = str_replace($this->fileBrowser->getPublicDirectory()->getPath(), '', $fileDestination->getPath());

        // return the full URL
        return $this->baseUrl . $image;
    }

    /**
     * Gets the cache file for the image source
     * @param pallo\library\system\file\File $source image source to get a
     * cache file for
     * @param string $thumbnailer Name of the thumbnailer
     * @param integer $width The width of the cached image
     * @param integer $height The height of the cached image
     * @return pallo\library\system\file\File unique name for a source file, in
     * the cache directory, with the thumbnailer, width and height encoded into
     */
    private function getCacheFile(File $source, $thumbnailer = null, $width = 0, $height = 0) {
        $filename = md5(
            $source->getPath() .
            '-thumbnailer=' . $thumbnailer .
            '-width=' . $width .
            '-height=' . $height
        );

        $filename .= '-' . $source->getName();

        return $this->path->getChild($filename);
    }

}