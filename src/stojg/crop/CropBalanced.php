<?php

namespace stojg\crop;

/**
 * CropBalanced
 *
 * This class calculates the most interesting point in the image by:
 *
 * 1. Dividing the image into four equally squares
 * 2. Find the most energetic point per square
 * 3. Finding the images weighted mean interest point
 *
 * @todo Refactor to make cleaner
 * @todo Rename the class to something more sensible
 */
class CropBalanced extends Crop
{
	/**
	 * get special offset for class
	 *
	 * @param  \Imagick $original
	 * @param  int	  $targetWidth
	 * @param  int	  $targetHeight
	 * @return array
	 */
	protected function getSpecialOffset(\Imagick $original, $targetWidth, $targetHeight)
	{
		return $this->getRandomEdgeOffset($original, $targetWidth, $targetHeight);
	}

	/**
	 *
	 * @param  \Imagick $original
	 * @param  int	  $targetWidth
	 * @param  int	  $targetHeight
	 * @return array
	 */
	protected function getRandomEdgeOffset(\Imagick $original, $targetWidth, $targetHeight)
	{
		$measureImage = clone($original);
		// Enhance edges with radius 1
		$measureImage->edgeimage(1);
		// Turn image into a grayscale
		$measureImage->modulateImage(100, 0, 100);
		// Turn everything darker than this to pitch black
		$measureImage->blackThresholdImage("#070707");
		// Get the calculated offset for cropping
		return $this->getOffsetBalancedForImage($measureImage, $targetWidth, $targetHeight);
	}

	/**
	 *
	 * @param  int   $targetWidth
	 * @param  int   $targetHeight
	 * @return array
	 * @todo refactor so it follows DRY
	 */
	public function getOffsetBalanced($targetWidth, $targetHeight)
	{
		return $this->getOffsetBalancedForImage($this->originalImage, $targetWidth, $targetHeight);
	}

	/**
	 * @param \Imagick $image
	 * @param $targetWidth
	 * @param $targetHeight
	 * @return array
	 * @throws \Exception
	 */
	private function getOffsetBalancedForImage(\Imagick $image, $targetWidth, $targetHeight)
	{
		$size = $image->getImageGeometry();

		$points = array();

		$halfWidth = ceil($size['width']/2);
		$halfHeight = ceil($size['height']/2);

		// First quadrant
		$clone = clone($image);
		$clone->cropimage($halfWidth, $halfHeight, 0, 0);
		$point = $this->getHighestEnergyPoint($clone);
		$points[] = array('x' => $point['x'], 'y' => $point['y'], 'sum' => $point['sum']);

		// Second quadrant
		$clone = clone($image);
		$clone->cropimage($halfWidth, $halfHeight, $halfWidth, 0);
		$point = $this->getHighestEnergyPoint($clone);
		$points[] = array('x' => $point['x']+$halfWidth, 'y' => $point['y'], 'sum' => $point['sum']);

		// Third quadrant
		$clone = clone($image);
		$clone->cropimage($halfWidth, $halfHeight, 0, $halfHeight);
		$point = $this->getHighestEnergyPoint($clone);
		$points[] = array('x' => $point['x'], 'y' => $point['y']+$halfHeight, 'sum' => $point['sum']);

		// Fourth quadrant
		$clone = clone($image);
		$clone->cropimage($halfWidth, $halfHeight, $halfWidth, $halfHeight);
		$point = $point = $this->getHighestEnergyPoint($clone);
		$points[] = array('x' => $point['x']+$halfWidth, 'y' => $point['y']+$halfHeight, 'sum' => $point['sum']);

		// get the totalt sum value so we can find out a mean center point
		$totalWeight = array_reduce(
			$points,
			function ($result, $array) {
				return $result + $array['sum'];
			}
		);

		$centerX = 0;
		$centerY = 0;

		// If we found a center point, made the calculations to found the coords
		if ($totalWeight) {
			// Calulate the mean weighted center x and y
			$totalPoints = count($points);
			for ($idx=0; $idx < $totalPoints; $idx++) {
				$centerX += $points[$idx]['x'] * ($points[$idx]['sum'] / $totalWeight);
				$centerY += $points[$idx]['y'] * ($points[$idx]['sum'] / $totalWeight);
			}
		}

		// From the weighted center point to the topleft corner of the crop would be
		$topleftX = max(0, ($centerX - $targetWidth / 2));
		$topleftY = max(0, ($centerY - $targetHeight / 2));

		// If we don't have enough width for the crop, back up $topleftX until
		// we can make the image meet $targetWidth
		if ($topleftX + $targetWidth > $size['width']) {
			$topleftX -= ($topleftX+$targetWidth) - $size['width'];
		}
		// If we don't have enough height for the crop, back up $topleftY until
		// we can make the image meet $targetHeight
		if ($topleftY+$targetHeight > $size['height']) {
			$topleftY -= ($topleftY+$targetHeight) - $size['height'];
		}

		return [
			'x' => (int) $topleftX,
			'y' => (int) $topleftY
		];
	}

	/**
	 * By doing random sampling from the image, find the most energetic point on the passed in
	 * image
	 *
	 * @param  \Imagick $image
	 * @return array
	 */
	protected function getHighestEnergyPoint(\Imagick $image)
	{
		$size = $image->getImageGeometry();
		// It's more performant doing random pixel uplook via GD
		$im = imagecreatefromstring($image->getImageBlob());
		if ($im === false) {
			$msg = 'GD failed to create image from string';
			throw new \Exception($msg);
		}
		$xcenter = 0;
		$ycenter = 0;
		$sum = 0;
		// Only sample 1/50 of all the pixels in the image
		$sampleSize = round($size['height']*$size['width'])/50;

		for ($k=0; $k<$sampleSize; $k++) {
			$i = mt_rand(0, $size['width']-1);
			$j = mt_rand(0, $size['height']-1);

			$rgb = imagecolorat($im, $i, $j);
			$r = ($rgb >> 16) & 0xFF;
			$g = ($rgb >> 8) & 0xFF;
			$b = $rgb & 0xFF;

			$val = $this->rgb2bw($r, $g, $b);
			$sum += $val;
			$xcenter += ($i+1)*$val;
			$ycenter += ($j+1)*$val;
		}

		if ($sum) {
			$xcenter /= $sum;
			$ycenter /= $sum;
		}

		$point = array('x' => $xcenter, 'y' => $ycenter, 'sum' => $sum/round($size['height']*$size['width']));

		return $point;
	}
}
