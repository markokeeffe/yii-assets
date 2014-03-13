<?php
/**
 * Author:  Mark O'Keeffe

 * Date:    22/11/13
 *
 * [Yii Workbench] VAssetController.php
 */

class AssetController extends CController {


  /**
   * The asset manager class
   *
   * @var \MOK\Assets\Assets
   */
  protected $assets;

  /**
   * Inject dependencies
   */
  public function __construct()
  {
    $this->assets = Yii::app()->Assets;
  }

  /**
   * Output the minified source for a group of files
   *
   * @param $group
   * @param $type
   * @param $lm
   *
   * @throws CHttpException
   */
  public function actionIndex($group, $type, $lm)
  {

    $source = $this->assets->getGroupSource($group, $type, $lm);

    switch($type) {
      case 'css' : $mime = 'text/css'; break;
      case 'js' : $mime = 'application/javascript'; break;
      default : throw new CHttpException(500, 'Invalid asset type.');
    }

    header('Content-type: '.$mime);
    echo $source;
    Yii::app()->end();
  }

}
