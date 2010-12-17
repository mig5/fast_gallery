<?php
// $Id: fast_gallery.class.php,v 1.8 2008/09/11 04:42:48 rapsli Exp $

/**
 * This is a helper class doing most of the db calls
 * 
 * @author Raphael Schär - www.schaerwebdesign.ch
 */
 
/**
 * This is a helper class doing most of the db calls
 */ 
class FastGallery {
  private static $instance = null;
  
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

  public function rescanGallery(){
    $path = variable_get('fast_gallery_path_absolut', 'sites/default/files/');
    $files = $this->exploreDir($path, TRUE);
    $this->updateDb($files);
    $this->deletePics();
    if(REGISTER_FAST_GALLERY){
        fast_gallery_register();
    }
  }
  
  /**
   * We are fetching a random picture from the db
   * @return Object gid, path
   */
  public function getRandomPicture(){
    $arPics = $this->getPicsFlat();
    $randId = array_rand($arPics);
    return $arPics[$randId];
  }

  /**
   * We are going the information to the db
   * so it can be faster processed later on
   */
  public function updateDb($ar_files, $parent="top") {
    $value = '';
    foreach ($ar_files as $key => $value) {
      if (!is_array($value)) {
        $folder = $this->getFolderInPath($value);
      }
      if (is_array($value)) { //is a subfolder
        if($folder==NULL){
          $arTmp = explode("/",variable_get('fast_gallery_path_absolut','sites/default/files/'));
          $folder = $arTmp[count($arTmp)-2];
        }
        $this->updateDb($value, $folder);
      } else {
        $sql = "SELECT gid FROM {fast_gallery} WHERE path = '%s'";
        //print 'insert '.$value.'<br/>';
        $result = db_query($sql, $value);
        
        if (!db_fetch_array($result)) { //there's no entry in the db for this file
//          $sql = "INSERT INTO {fast_gallery} " .
//          "(path,folder,parent) " .
//          "VALUES ('%s','%s','%s')";
//          db_query($sql, $value, $folder, $parent);
          $dbObject['path'] = $value;
          $dbObject['folder'] = $folder;
          $dbObject['parent'] = $parent;
          drupal_write_record('fast_gallery',$dbObject);
          $gid = db_last_insert_id("{fast_gallery}", "gid");
          
          if(function_exists("exif_read_data")){//seems that some hosters have problems
            $this->handleExif($value, $gid); //and also update exif Table
          }
        }
      }
    }
  }

  public function handleExif($file, $gid) {
    $fileType = substr($file, count($file) - 4, 3);
    
    if (strtolower($fileType) != "jpg") {
      return;
    }
    $exif = exif_read_data($file, 0, true);
//    foreach ($exif as $key => $section)
//    {
////        foreach ($section as $name => $val)
////        {
////            echo "$key.$name: $val <br/>";
////            print_r($val);
////        }
//        print_r($exif['EXIF']['DateTimeOriginal']);
//    }
    //TODO: Create table and write them in there...
    //echo "========<br/>";

    $param['model'] = $exif['IFD0']['Model']; //for example Canon EOS 350D DIGITAL
    $param['exposureTime'] = $exif['EXIF']['ExposureTime'];
    $param['dateTaken'] = $exif['EXIF']['DateTimeOriginal'];
    $param['ISOSpeedRatings'] = $exif['EXIF']['ISOSpeedRatings'];
    $param['title'] = $exif['WINXP']['Title'];
    $param['comment'] = $exif['WINXP']['Comments'];
    $param['keyword'] = explode(" ", $exif['WINXP']['Keywords']);
    $param['fileCreated'] = filemtime($file);

    foreach ($param as $key => $value) {
      if ($value != '') {
        if (is_array($value)) {
          foreach ($value as $v) {
            if($v != ''){
              $dbObject['field'] = $key;
              $dbObject['value'] = $v;
              $dbObject['gid'] = $gid;
              drupal_write_record('fast_gallery_exif',$dbObject);
            }
          }
        } else {
            $dbObject['field'] = $key;
            $dbObject['value'] = $value;
            $dbObject['gid'] = $gid;
            drupal_write_record('fast_gallery_exif',$dbObject);
        }
      }
    }
  }

  /**
   * Find the pics that are not in the filesystem anymore
   * and delete them from the db.
   */
  public function deletePics() {
    $arPics = $this->getAllPicsFlat();
    foreach ($arPics as $value) {
      
      if (!file_exists($value->path)) {
        
        $sql = "DELETE FROM {fast_gallery} WHERE gid = %d";
        db_query($sql, $value->gid);
        $sql = "DELETE FROM {fast_gallery_exif} WHERE gid = %d";
        db_query($sql, $value->gid);
      }
    }
  }

  /**
   * just emptying the gallery table
   */
  public function clearGallery() {
    db_query("TRUNCATE TABLE {fast_gallery}");
    db_query("TRUNCATE TABLE {fast_gallery_exif}");
  }

  /**
   * we save the absolut path to the gallery
   * will be needed later on to retrieve the right folder again
   * 
   */
  public function setAbsolutPath() {
    $path_absolut = '';
    if (variable_get('fast_gallery_path_option', "files") != -1) {
      $path_absolut = variable_get('fast_gallery_path_option', "sites/default/files/");
    } else {
      $path_absolut = variable_get('fast_gallery_path', "sites/default/files/");
    }
    variable_set('fast_gallery_path_absolut', $path_absolut);
  }

  /**
   * Get only the pics from a folder, but then also list all the
   * child folders as albums so there is going to be a hierarchy
   */
  public function getPicsHierarchy($folder = '') {
    //let's sort this sql by path name
    if(variable_get('fast_gallery_sort','path') == 'path'){
    $result = pager_query("SELECT path,folder,gid " .
                          "FROM {fast_gallery} " .
                          "WHERE folder = '$folder' " .
                          "ORDER BY path ".
                          variable_get('fast_gallery_direction','asc'), 
                          variable_get('fast_gallery_nr_per_page', 30));
    }
    //let's sort the query by date from the exif
    //Pictures with no exif date will not be shown
    elseif(variable_get('fast_gallery_sort','path') == 'date'){
      $result = pager_query("SELECT path,folder,fg.gid " .
                          "FROM {fast_gallery} as fg, {fast_gallery_exif} as fe " .
                          "WHERE folder = '$folder' " .
                          "AND fg.gid = fe.gid " .
                          "AND fe.field = 'dateTaken' " .
                          "ORDER BY path ".
                          variable_get('fast_gallery_direction','asc'), 
                          variable_get('fast_gallery_nr_per_page', 30));
    }
    elseif(variable_get('fast_gallery_sort','path') == 'filec'){
      $result = pager_query("SELECT path,folder,fg.gid " .
                          "FROM {fast_gallery} as fg, {fast_gallery_exif} as fe " .
                          "WHERE folder = '$folder' " .
                          "AND fg.gid = fe.gid " .
                          "AND fe.field = 'fileCreated' " .
                          "ORDER BY value ".
                          variable_get('fast_gallery_direction','asc'), 
                          variable_get('fast_gallery_nr_per_page', 30));
    }
    //incase the user wishes to display the images randomly
//    elseif(variable_get('fast_gallery_sort','path') == 'rand'){
      //TODO: make a random function -> maybe it's better to 
      //first read them from the db and then shuffle the array
//    }
    //just display them without options
    else{
      $result = pager_query("SELECT path,folder,gid " .
                          "FROM {fast_gallery} " .
                          "WHERE folder = '$folder' ",
                          variable_get('fast_gallery_nr_per_page', 30));
    }
    $picBag = array ();
    while ($row = db_fetch_object($result)) {
      $picBag[] = $row;
      $parent = $row->folder;
    }
    
    //get the folders
    $result = db_query("SELECT folder FROM {fast_gallery} WHERE parent != folder GROUP by folder");
    while($row = db_fetch_object($result)){
//      dsm($row->folder);
      $row2 = db_fetch_object(db_query("SELECT path,folder,fg.gid FROM {fast_gallery} AS fg,{fast_gallery_exif} AS fe " .
                       "WHERE parent = '%s' " .
                       "AND fg.gid = fe.gid " .
                       "AND parent != folder " .
                       "AND folder = '%s'" .
                       "ORDER BY folderimage desc" .
                       " LIMIT 0,1" 
                       , $folder,$row->folder));
        
        if($row2 != ''){
          $row2->isFolder = TRUE;
          array_unshift($picBag, $row2);
        }
    }
    return $picBag;
  }
  

  /**
   * get the exif data of a picture
   */
  public function getExif($gid) {
    $sql = "SELECT * FROM {fast_gallery_exif} WHERE gid=%d";
    $result = db_query($sql, $gid);
    $out = array ();
    while ($row = db_fetch_object($result)) {
      $out[$row->field] = $row->value;
    }

    return $out;
  }

  /**
  * Fetch all the pics from the db
  * It's just going to be one big list using a pager query
  * @return array
  */
  public function getPicsFlat() {
    $sql = "SELECT gid,path,gid FROM {fast_gallery}";
    $result = pager_query($sql,variable_get('fast_gallery_nr_per_page', 30));
    $picBag = array ();
    while ($row = db_fetch_object($result)) {
      $picBag[] = $row;
    }
    return $picBag;
  }
  
  /**
   * Similar function to getPicsFlat but without the
   * pager_query. This is mainly used for internal uses
   * like the delete function.
   * @return array
   */
  private function getAllPicsFlat(){
     $sql = "SELECT gid,path,gid FROM {fast_gallery}";
    $result = db_query($sql);
    $picBag = array ();
    while ($row = db_fetch_object($result)) {
      $picBag[] = $row;
    }
    return $picBag;
  }

  /**
  * Get all images on a given directory
  *
  * @param $path
  *  String. The absolute path to start the scanner
  * @param $path
  *  Boolean. TRUE for recursive scanning
  * @return
  *  Array. All images' paths
  */
  public function exploreDir($path = '', $recursive = FALSE) {

    /*$arTmp = explode("",$path);
    if($arTmp[count($arTmp)-1] != "/"){
      $arTmp[count($arTmp)] = "/";
    }*/

    // A list of image extensions. It should be on lower and
    // upper cases to work on non GNU systems, like Solaris and Windows
    $exts = array (
      'png',
      'gif',
      'jpeg',
      'jpg',
      'bmp',
      'PNG',
      'GIF',
      'JPEG',
      'JPG',
      'BMP'
    );

    // Get all files from a given path
    $files["$path"] = array ();
    foreach ($exts as $ext) {
      
      $files["$path"] = array_merge((array)$files["$path"], (array)glob("$path*.$ext"));
//      $files["$path"] = array_merge($files["$path"], glob("$path*.$ext"));
    }

    // If its a recursive scan, enter in all directories
    // and scan for more image files
    if ($dirs = glob("$path*", GLOB_ONLYDIR) and !empty ($recursive)) {
      foreach ($dirs as $dir) {
        $files["$path"] = array_merge($files["$path"], $this->exploreDir("$dir/", TRUE));
      }
    }

    return $files;
  }

  /**
   * we get a path like my/path/test.jpg
   * So this would then extract the folder in which
   * test.jpg is -> in this example this would be path
   */
  public function getFolderInPath($path, $i = 0) {
    $arTmp = explode("/", $path);
    $folder = $arTmp[count($arTmp) - (2 + $i)];
    return $folder;
  }

  /**
   * we are building a menu tree with all the folders in the gallery
   * @param String $path - the path from where to start
   * @return Array An Assoziative Array of Strings with the folder paths $string['path']=>array(...)
   */
  public function getAllFolders($path) {
    $files["$path"] = array ();
    if ($dirs = glob("$path*", GLOB_ONLYDIR)) {
      foreach ($dirs as $dir) {
        $files["$path"] = array_merge($files["$path"], $this->getAllFolders("$dir/"));
      }
    }
    return $files;
  }

  /**
   * We are going through the array with the hierarchy and flatten it.
   * Afterwards it's just going to be one huge list of pics
   * @param array $arFolders
   */
  public function flattenAllFolders($arFolders) {
    while (list ($key, $value) = each($arFolders)) {
      if (!preg_match("|.*imagecache.*|", $key)) { //we don't want imagecache folders in here
        $result .= $key . '%&';
      }

      if (is_array($value)) {
        $result .= $this->flattenAllFolders($value);
      }
    }
    return $result;
  }

  public function prepareListOfFolders($arFolders) {
    $arResult = array ();
    foreach ($arFolders as $key => $value) {
      $arResult[$value] = $value;
    }
    $arResult['-1'] = t('enter path manually...');
    return $arResult;
  }
}