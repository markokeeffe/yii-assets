yii-assets
==========

An integration of <a href="https://github.com/kriswallsmith/assetic" target="_blank">Assetic</a> with a custom extension of CClientScript to automatically minify, concatenate and upload & serve assets from a CDN.

## Installation ##

Add the Assets application component to your config:

```php
  'Assets' => array(
    'class' => '\MOK\Assets\Assets',
    'controllerId' => 'asset',
  ),
```

Set the 'clientScript' component to the ClientScript class:

```php
  'clientScript'=>array(
    'class'=>'vendor.mok.yii-assets.src.components.ClientScript',
    'minify' => false,
  ),
```

## Configuration ##

Enable minification by setting the 'minify' parameter on clientScript to true:

```php
  'clientScript'=>array(
    'class'=>'vendor.mok.yii-assets.src.components.ClientScript',
    'minify' => true,
  ),
```

## Enabling a CDN ##

Ensure you have a remote repository available as an application component e.g. Rackspace:

```php

  'RackspaceRepository' => array(
    'class' => '\MOK\RepoRackspace\RackspaceRepository',
    'config' => array(
      ...
    ),
  ),

```

Specify the application component name for the remote repository in the config array for the VAssets component:

```php
  'Assets' => array(
    'class' => '\MOK\Assets\Assets',
    'controllerId' => 'asset',
    'remoteComponent' => 'RackspaceRepository',
    'useCDN' => true,
  ),
```

## Managing Assets ##

You can use the clientScript component as normal to add assets, with some minor improvements. Note the `registerScriptDirectory()` method to get all script files from a directory:

```php

  Yii::app()->clientScript->registerCoreScript('jquery', CClientScript::POS_END);
  Yii::app()->clientScript->registerCoreScript('jquery.ui', CClientScript::POS_END);
  Yii::app()->clientScript->registerScriptDirectory(bu('js/functions'), CClientScript::POS_END);
  Yii::app()->clientScript->registerScriptDirectory(bu('js/behaviors'), CClientScript::POS_END);
  Yii::app()->clientScript->registerScriptFile(Yii::app()->theme->baseUrl.'/js/bootstrap.js', CClientScript::POS_END);
  Yii::app()->clientScript->registerScriptFile(bu('js/application.js'), CClientScript::POS_END);

```
