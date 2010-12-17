<?php
/**
 * @author Raphael Schär - www.schaerwebdesign.ch
 */

/**
 * This class provides a very simple cache for the thumbnails, incase imagecache
 * is not being used (or can't be used)
 */
class FastGalleryCache {
  static private $instance = null;
  
  /**
   * We are implementing a singleton pattern
   */
  private function __construct(){
    
  }
  
  public function getInstance(){
    if(is_null(self::$instance)){
      self::$instance = new self;
    }
    return self::$instance;
  }
  
  /**
   * Let's check if a file already exists
   * @param String $name
   * @return boolean
   */
  private function imageExist($name){
    return file_exists($name.'.thumb');
  }
  
  /**
   * Create a thumb and copy it into the same folder but with the extension .thumb
   * @param String $name
   * @param String $filename
   * @param int $new_w
   * @param int $new_h
   * @param String $type
   */
  public function createthumb($name, $filename, $new_w, $new_h, $type) {
    
    $arTmp = explode(".",$name);
    $type = $arTmp[count($arTmp)-1];
    if($this->imageExist($name)){
      return true;
    }
    $system = explode('.', $name);
    if ($type == "jpg" || $type == "jpeg") {
      $src_img = imagecreatefromjpeg($name);
    }
    if ($type == "png") {
      $src_img = imagecreatefrompng($name);
    }
    if ($type == "gif") {
      $src_img = imagecreatefromgif($name);
    }
    $old_x = imageSX($src_img);
    $old_y = imageSY($src_img);
    if ($old_x > $old_y) {
      $thumb_w = $new_w;
      $percent = ($new_w * 100) / $old_x;
      $thumb_h = ($percent * $old_y) / 100;
    }
    if ($old_x < $old_y) {
      $percent = ($new_h * 100) / $old_y;
      $thumb_w = ($percent * $old_x) / 100;
      $thumb_h = $new_h;
    }
    if ($old_x == $old_y) {
      $thumb_w = $new_w;
      $thumb_h = $new_h;
    }
    $dst_img = ImageCreateTrueColor($thumb_w, $thumb_h);
    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);

    if ($type == "png") {
      imagepng($dst_img, $name. '.thumb');
    }
    if ($type == "gif") {
      imagegif($dst_img, $name. '.thumb');
    }
    if ($type == "jpg" || $type == "jpeg") {
      imagejpeg($dst_img, $name . '.thumb');
    }
    imagedestroy($dst_img);
    imagedestroy($src_img);
  }
  
  /**
   * Let's just remove all the thumbs
   */
  public function flushThumbs($path = '', $recursive = FALSE){
    $exts = array (
      'thumb',
    );
    // Get all files from a given path
    $files["$path"] = array ();
    foreach ($exts as $ext) {
      $Arpath = (array)glob("$path*.$ext");
    }
    foreach ( $Arpath as $img ) {
        unlink($img);   
    }

    // If its a recursive scan, enter in all directories
    // and scan for more image files
    if ($dirs = glob("$path*", GLOB_ONLYDIR) and !empty ($recursive)) {
      foreach ($dirs as $dir) {
        $this->flushThumbs("$dir/", TRUE);
      }
    }

    return $files;
  }
}