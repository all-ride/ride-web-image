<?php

namespace ride\web\image;

use ride\library\dependency\DependencyInjector;
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
     * @var \ride\library\system\file\browser\FileBrowser
     */
    private $fileBrowser;

    /**
     * Instance of the dependency injector
     * @var \ride\library\dependency\DependencyInjector
     */
    private $dependencyInjector;

    /**
     * Base URL for the images
     * @var string
     */
    private $baseUrl;

    /**
     * The path for the processed images
     * @var \ride\library\system\file\File
     */
    private $path;

    /**
     * Constructs a image URL generator
     * @param \ride\library\system\file\browser\FileBrowser $fileBrowser
     * @param string $path Relative path in the public directory to save the
     * processed images
     * @return null
     */
    public function __construct(FileBrowser $fileBrowser, DependencyInjector $dependencyInjector, $baseUrl, $path = null) {
        $this->fileBrowser = $fileBrowser;
        $this->dependencyInjector = $dependencyInjector;
        $this->baseUrl = $baseUrl;

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
     * @return \ride\library\system\file\File
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
            // relative file, get the image from the file browser
            $fileSource = $this->fileBrowser->getPublicFile($image);
            if (!$fileSource) {
                $fileSource = $this->fileBrowser->getFile($image);
            }

            if (!$fileSource) {
                throw new ImageException('Could not generate URL for ' . $image . ': file not found');
            }
        }

        $publicDirectory = $this->fileBrowser->getPublicDirectory()->getAbsolutePath();
        $useThumbnailer = $thumbnailer && ($width > 0 || $height > 0);
        $isPublicFile = strpos($fileSource->getAbsolutePath(), $publicDirectory) === 0;

        if (!$useThumbnailer && $isPublicFile) {
            // no processing needed, use source file since it's public
            $fileDestination = $fileSource;
        } else {
            // processing needed
            $fileDestination = $this->getCacheFile($fileSource, $thumbnailer, $width, $height);

            if (!$fileDestination->exists() || $fileSource->getModificationTime() > $fileDestination->getModificationTime()) {
                // needed file does not exist or has been changed
                if ($useThumbnailer) {
                    $thumbnailer = $this->dependencyInjector->get('ride\\library\\image\\thumbnail\\Thumbnailer', $thumbnailer);
                    $imageFactory = $this->dependencyInjector->get('ride\\library\\image\\io\\ImageFactory');

                    $image = $imageFactory->read($fileSource);

                    $thumbnail = $thumbnailer->getThumbnail($image, new Dimension($width, $height));
                    if ($image !== $thumbnail) {
                        // thumbnail generated, write to cache file
                        $imageFactory->write($fileDestination, $thumbnail);
                    } elseif ($isPublicFile) {
                        // no processing done and a public file, use source file since it's public
                        $fileDestination = $fileSource;
                    } else {
                        // no processing done and a non-public file, copy to the cache file
                        $fileSource->copy($fileDestination);
                    }
                } else {
                    // non-public file, copy to the cache file
                    $fileSource->copy($fileDestination);
                }
            }
        }

        // make the image relative to the public directory
        $image = str_replace($publicDirectory, '', $fileDestination->getAbsolutePath());

        // return the full URL
        return $this->baseUrl . $image;
    }

    /**
     * Gets the cache file for the image source
     * @param \ride\library\system\file\File $source image source to get a
     * cache file for
     * @param string $thumbnailer Name of the thumbnailer
     * @param integer $width The width of the cached image
     * @param integer $height The height of the cached image
     * @return \ride\library\system\file\File unique name for a source file, in
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
