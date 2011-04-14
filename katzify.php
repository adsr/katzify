<?php

/*
This file is part of Katzify.

    Katzify is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Katzify is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Katzify.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * Image processing class that converts an input image into a "Dr. Katz"
 * style animated GIF. Accepted input image formats: gif, jpeg (experimental),
 * and png (experimental). This class relies on the gd graphics library and
 * gifsicle for now.
 * 
 * @author Adam Saponara
 *
 * @todo b/w threshold filter after erode process
 * @todo test with png, jpeg input
 * @todo abstract gd image resource as separate class
 * @todo option to only trace certain colors in image
 * @todo remove assumption that white is clear/bg color
 * @todo remove dependency on gifsicle, kludgy
 */

class Katzify {

	/**
	 * Renders an image at $img_in_path as an animated gif to $img_out_path
	 * using the $tracer Tracer and the $animator Animator.
	 * 
	 * @param string $img_in_path path of image to animate
	 * @param string $img_out_path path of resulting animated gif
	 * @param Tracer $tracer an image tracer
	 * @param Animator $animator an image animator
	 */
	public static function render($img_in_path, $img_out_path, Tracer $tracer, Animator $animator) {
		
		// check if gd is available
		if (!function_exists('imagecreatefromgif')) {
			throw new Exception('gd not available');
		}

		// load image as gd resource		
		$img_res = self::getImageResource($img_in_path);
		
		// identify and trace all shapes in image
		$shapes = $tracer->trace($img_res);
		
		// create animation for each shape
		$animation = $animator->animate($shapes);
		
		// convert frames to animated gif
		self::writeAnimationAsGif($img_res, $img_out_path, $animation);
		
	}
	
	/**
	 * Loads a gd image resource of the image at $img_path.
	 *
	 * @param string $img_path path of image to load
	 * @return resource gd image resource
	 */
	protected static function getImageResource($img_path) {
	
		// check if file is readable
		if (!is_readable($img_path)) {
			throw new Exception("Image at $img_path is not readable.");
		}
		
		// get gd image resource
		// guess file type by extension; default to gif
		$img_res = null;
		$ext = strtolower(substr(strrchr($img_path, '.'), 1));
		switch ($ext) {
			default:
			case 'gif':
				$img_res = imagecreatefromgif($img_path);
				break;
			case 'jpg':
			case 'jpeg':
				$img_res = imagecreatefromjpeg($img_path);
				break;
			case 'png':
				$img_res = imagecreatefrompng($img_path);
				break;
		}
		
		// make sure we have an image resource
		if (!$img_res) {
			throw new Exception("Failed to load image");
		}
		
		return $img_res;
	}
	
	/**
	 * Creates an animated gif using the sequence of shapes in $frames
	 * and writes it to disk at $img_path.
	 *
	 * @param resource $img_res original image
	 * @param string $img_path path to write gif to
	 * @param array $animation array of frames, each frame an array of shapes
	 */
	protected static function writeAnimationAsGif($img_res, $img_path, array &$animation) {
	
		// @todo shape_animations as Animation class or something
		//       array of array of... is too tightly coupled to Animator


		// set width and height of image		
		$w = imagesx($img_res);
		$h = imagesy($img_res);
		
		// for each frame in animation...
		for ($i = 0, $frame_count = count($animation); $i < $frame_count; $i++) {
			
			// create temp image for this frame
			$img_frame_res = imagecreate($w, $h);
			
			// set ink color
			// @todo use original color of shape or parameterize
			$clear = imagecolorallocate($img_frame_res, 0xff, 0xff, 0xff);
			$ink = imagecolorallocate($img_frame_res, 0, 0, 0);
			
			// ref to shapes in animation
			$shapes = &$animation[$i];
		
			// for each shape in frame...
			foreach ($shapes as $shape) {

				// num points in shape
				$num_coords = count($shape);
			
				// reformat coords for imagepolygon
				// it expects [x,y,x,y,...] instead of [[x,y],[x,y],...]
				$coords = array();
				foreach ($shape as $xy) {
					$coords[] = $xy[0];
					$coords[] = $xy[1];
				}
				
				assert('$num_coords === count($coords) / 2');
				
				// skip if we can't make a polygon
				if ($num_coords < 3) {
					continue;
				}
			
				// draw polygon on frame
				imagepolygon($img_frame_res, $coords, $num_coords, $ink);
				
			}
			
			// write frame to disk
			self::writeImageAsGif($img_frame_res, 'frame_'.$i.'.gif');
			
		}
		
		// use gifsicle to animate the frames
		// @todo this is temp, eventually lose gifsicle
		$cmd = 'gifsicle --loop -O1 --delay 10 frame_*.gif > '.$img_path;
		echo shell_exec($cmd);
		
		// delete temp images
		foreach (glob('frame_*.gif') as $f) unlink($f);
	
	}
	
	/**
	 * Write a gd image resource to $img_path as a gif
	 * 
	 * @param resource $img_res image
	 * @param string $img_path target path
	 */
	public static function writeImageAsGif($img_res, $img_path) {

		ob_start();
		imagegif($img_res);
		file_put_contents($img_path, ob_get_clean());
		
	}
	
}

/* @todo shape + animation abstraction
class Shape extends ArrayObject {
	function addPoint($x, $y) {
		$this->offsetSet(0, $x);
		$this->offsetSet(1, $y);
	}
}
*/

/**
 * Traces an image using the 'ant' method.
 */
class AntTracer implements Tracer {

	const DIR_UP = 1; // state when ant is facing up, etc
	const DIR_DOWN = 2;
	const DIR_LEFT = 3;
	const DIR_RIGHT = 4;
	
	const ERODE_BLOT = 1; // blot square will have sides of length ERODE_BLOT * 2 + 1

	/** 
	 * Trace the image, returning an array of located shapes.
	 * 
	 * @param string $im gd image resource to trace
	 * @return array array of distinct shapes
	 */
	function trace($im) {
	
		// find width and height of image
		$w = imagesx($im);
		$h = imagesy($im);
	
		// white is the assumed background color
		// @todo parameterize or abstract this somehow
		$clear_color = imagecolorexact($im, 255, 255, 255);
		assert('$clear_color !== -1');
		
		// @todo 'erode' (thicken lines) parameter? for tracing non-closed
		//       groups of pixels
		$im = $this->erodeImage($im, $clear_color, $w, $h);

		// loop while we can locate a new shape
		$shapes = array();
		while (($xy = $this->locateShape($im, $clear_color, $w, $h, $shapes)) !== false) {
			
			// trace the new shape and add it to the list
			assert('isset($xy[0]) && isset($xy[1])');
			$shapes[] = $this->traceShape($im, $clear_color, $w, $h, $xy[0], $xy[1]);
			assert('count($shapes[count($shapes) - 1]) > 0');

			// clear out shape we just traced
			imagefill($im, $xy[0], $xy[1], $clear_color);

		}
		
		return $shapes;

	}
	
	/**
	 * Erode the image by blotting each pixel with a square
	 *
	 * @param resource $im gd image
	 * @param integer $clear_color color to ignore
	 * @param integer $w width of image
	 * @param integer $h height of image
	 * @return resource eroded version of gd image
	 */
	protected function erodeImage($im, $clear_color, $w, $h) {
	
		// create blank image with same pallette
		$im_eroded = imagecreate($w, $h);
		$clr = imagecolorallocate($im_eroded, 255, 255, 255); //need this otherwise the image is black
		imagepalettecopy($im_eroded, $im);
		
		// for every pixel in image
		for ($x = 0; $x < $w; $x++) {	
			for ($y = 0; $y < $h; $y++) {

				// skip if it is a clear pixel
				$color = imagecolorat($im, $x, $y);
				if ($color === $clear_color) {
					continue;
				}
				
				// fill in square at this pixel
				for ($dx = self::ERODE_BLOT * -1; $dx <= self::ERODE_BLOT; $dx++) {
					for ($dy = self::ERODE_BLOT * -1; $dy <= self::ERODE_BLOT; $dy++) {
						
						// skip this pixel if out of image bounds
						if ($x + $dx < 0
						||  $y + $dy < 0
						||  $x + $dx >= $w
						||  $y + $dy >= $h
						) continue;
						
						// set pixel
						// don't set on image
						imagesetpixel($im_eroded, $x + $dx, $y + $dy, $color);
					}
				}
				
				// loop to next pixel
			}
		}
		
		// return new eroded image
		return $im_eroded;
		
	}
	
	/**
	 * Find first x,y coord of a previously unidentified shape
	 *
	 * @param resource $im gd image
	 * @param integer $clear_color index of color to be ignored in tracing
	 * @param integer $w image width
	 * @param integer $h image height
	 * @param array $identified_shapes array of previously identified shapes
	 * @return array|false 2-element array representing x,y coord or false
	 */
	function locateShape($im, $clear_color, $w, $h, array &$identified_shapes) {

		// look from bottom-right corner of image
		for ($y = $h - 1; $y >= 0; $y--) {
			for ($x = $w - 1; $x >= 0; $x--) {

				// get color at pixel
				$color = imagecolorat($im, $x, $y);
				
				// ignore if clear
				if ($color === $clear_color) {
					continue;
				}
					
				// found it!
				return array($x, $y);
			}
		}
		
		// did not find anything new
		return false;

	}
	
	/**
	 * Trace shape starting at $x, $y in $im ignoring $clear_color
	 *
	 * @param resource $im gd image
	 * @param integer $clear_color index of color to be ignored in tracing
	 * @param integer $w image width
	 * @param integer $h image height
	 * @param integer $start_x starting x coord
	 * @param integer $start_y starting y coord
	 * @param array $identified_shapes array of previously identified shapes
	 * @return array array of x,y coords of shape
	 */
	function traceShape($im, $clear_color, $w, $h, $start_x, $start_y) {

		$x = $start_x;
		$y = $start_y;
		$direction = self::DIR_UP;
		$shape = array();
		
		do {
			
			// get color at pixel
			$color = imagecolorat($im, $x, $y);
			
			if ($color !== $clear_color) {
				// color pixel, record coord and turn left
				$shape[] = array($x, $y);
				switch ($direction) {
					case self::DIR_UP: $direction = self::DIR_LEFT; break;
					case self::DIR_DOWN: $direction = self::DIR_RIGHT; break;
					case self::DIR_LEFT: $direction = self::DIR_DOWN; break;
					case self::DIR_RIGHT: $direction = self::DIR_UP; break;
				}
			}
			else {
				// blank pixel, turn right
				switch ($direction) {
					case self::DIR_UP: $direction = self::DIR_RIGHT; break;
					case self::DIR_DOWN: $direction = self::DIR_LEFT; break;
					case self::DIR_LEFT: $direction = self::DIR_UP; break;
					case self::DIR_RIGHT: $direction = self::DIR_DOWN; break;
				}
			}
			
			// move forward
			switch ($direction) {
				case self::DIR_UP: $y--; break;
				case self::DIR_DOWN: $y++; break;
				case self::DIR_LEFT: $x--; break;
				case self::DIR_RIGHT: $x++; break;
			}

			// loop while in bounds, and not back to the beginning		
		} while (($x >= 0 && $y >= 0 && $x < $w && $y < $h) && ($x != $start_x || $y != $start_y));
		
		return $shape;
	}
	
}


/**
 * Animates shapes using shaky 'Dr. Katz' style animation
 */
class KatzAnimator implements Animator {

	private $sloppiness;
	private $shakiness;
	private $shaky_freq;
	private $frame_count;
	
	function __construct($sloppiness, $shakiness, $shaky_freq, $frame_count) {
		$this->sloppiness = (float)$sloppiness;
		$this->shakiness = (integer)$shakiness;
		$this->shaky_freq = (float)$shaky_freq; //@todo validate, div by zero
		$this->frame_count = (float)$frame_count;
	}
	
	/**
	 * Make animation frames for each shape
	 * 
	 * @param array array of arrays of x,y coords
	 * @return array array of arrays of shapes (which are arrays of x,y coords)
	 */
	function animate(array &$shapes) {

		$animation = array();
		
		// make an animation of frame_count frames...
		for ($i = 0; $i < $this->frame_count; $i++) {
			
			$frame_shapes = array();
			
			// for each shape...
			foreach ($shapes as $shape) {

				// remove some coords from $shapes_copy according to sloppiness
				$num_coords_to_remove = floor($this->sloppiness * count($shape));
				while ($num_coords_to_remove > 0) {
					
					// find coord not already removed
					do {
						$rand_index = rand(0, count($shape) - 1);
					} while($shape[$rand_index] === null);
					
					// mark as null for removal
					$shape[$rand_index] = null;

					// decrement					
					$num_coords_to_remove -= 1;
				}
				
				// array_filter will remove all the null values
				// array_values will reindex numerically 0->n
				$shape = array_values(array_filter($shape));
				
				// now randomize coords according to shaky_freq and shakiness
				foreach ($shape as $k => $v) {
					if (rand(1, max(1, (integer)(1/$this->shaky_freq))) == 1) {
						$shape[$k][0] += rand($this->shakiness * -1, $this->shakiness);
						$shape[$k][1] += rand($this->shakiness * -1, $this->shakiness);
					}
				}
				
				// add frame to shape animation
				$frame_shapes[] = $shape;

			}
			
			// add shape animation to animation
			$animation[] = $frame_shapes;

		}
		
		return $animation;
		
	}

}


/**
 * Tracers examine an input gd image resource and return an array of shapes.
 */
interface Tracer {
	/** 
	 * @param string $img_res gd image resource to trace
	 * @return array array of shapes (which are arrays of x,y coords)
	 */
	function trace($img_res);
}

/**
 * Animators use an array of shapes to create an animation in whatever way
 * they wish to.
 */
interface Animator {
	/**
	 * @param array array of arrays of x,y coords
	 * @return array array of arrays of shapes (which are arrays of x,y coords)
	 */
	function animate(array &$shapes);
}

// @todo better command line parameters
if (isset($_SERVER['argv'][1]) && isset($_SERVER['argv'][2])) {
	Katzify::render($_SERVER['argv'][1], $_SERVER['argv'][2], new AntTracer(), new KatzAnimator(0.1, 1, 0.25, 5));
}
else {
	echo 'Usage: php '.$_SERVER['PHP_SELF'].' [input image] [output animation]';
}
