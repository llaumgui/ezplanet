<?php
/**
 * File containing the eZPlanet cronjobs
 *
 * @version //autogentag//
 * @package EZPlanet
 * @copyright Copyright (C) 2008-2012 Guillaume Kulakowski and contributors
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

$beginScript = microtime( true );
global $logInfo;

$cli->setUseStyles( true ); // enable colors
$iniSite = eZINI::instance( "site.ini" );
$iniPlanet = eZINI::instance( "ezplanet.ini" );

$logInfo = array(
    'countTotalBillets'  => 0,
    'countTotalBlogs' => 0,
    'blogOK' => 0,
    'blogKO' => 0,
    'logName' => $iniPlanet->variable( "PlanetSettings", "LogName" ),
    'logDir' => $iniSite->variable( "FileSettings", "VarDir" ) . "/log"
);


/*
 * Feth blogger eZContentClass
 */
$bloggerClassIdentifier = $iniPlanet->variable( "PlanetSettings", "BloggerClassIdentifier" );
$bloggerRSSLocationAttributeIdentifier =  $iniPlanet->variable( "PlanetSettings", "BloggerRSSLocationAttributeIdentifier" );
$bloggerContentClass = eZFunctionHandler::execute( 'content', 'class', array(
    'class_id' => $bloggerClassIdentifier,
) );
if ( ! $bloggerContentClass instanceof eZContentClass )
{
    $cli->error( 'There is no eZContentClass with "' . $bloggerClassIdentifier . '" identifier' );
    eZExecution::cleanExit();
}
if ( !$isQuiet )
{
    $cli->output( $cli->stylize( 'cyan', 'Use "' . $bloggerContentClass->name(). '" class' ) );
}


/*
 * Feth parent eZContentObjectTreeNode
 */
$bloggerParentNodeId = $iniPlanet->variable( "PlanetSettings", "BloggerParentNodeID" );
$bloggerParentNode= eZFunctionHandler::execute( 'content', 'node', array(
    'node_id' => $bloggerParentNodeId,
) );
if ( ! $bloggerParentNode instanceof eZContentObjectTreeNode )
{
    $cli->error( 'There is no eZContentObjectTreeNode with node_id=' . $bloggerParentNodeId );
    eZExecution::cleanExit();
}
if ( !$isQuiet )
{
    $cli->output( $cli->stylize( 'cyan', 'Use "' . $bloggerParentNode->getName(). '" parent node (' . $bloggerParentNodeId . ')' ) );
}


/*
 * Feth bloggers eZContentObjectTreeNode
 */
$bloggersNode= eZFunctionHandler::execute( 'content', 'tree', array(
    'parent_node_id' => $bloggerParentNodeId,
    'class_filter_type' => 'include',
    'class_filter_array' => array( $bloggerClassIdentifier )
) );
if ( !$bloggersNode )
{
    $cli->error( 'There is no blogger (' . $bloggerContentClass->name() .') in "' . $bloggerParentNode->getName() . '" (' . $bloggerParentNodeId . ')' );
    eZExecution::cleanExit();
}
$logInfo['countTotalBlogs'] = count( $bloggersNode );
if ( !$isQuiet )
{
    $cli->output( $cli->stylize( 'cyan', 'Find ' . $logInfo['countTotalBlogs'] . ' blogger(s)' ) );
}


/*
 * Start importation
 */
eZLog::write(
    "# BEGIN importation | " . $logInfo['countTotalBlogs'] . " blog(s)",
    $logInfo['logName'],
    $logInfo['logDir']
);



/*
 * Loop through all configured and active rss imports. If something goes wrong
 * while processing them, continue to next import
 */
foreach ( $bloggersNode as $rssImport )
{
    // Get RSSImport object
    $rssImportDataMap = $rssImport->dataMap();
    $rssSource = $rssImportDataMap[$bloggerRSSLocationAttributeIdentifier]->content();


    if( empty( $rssSource ) )
    {
        $cli->warning( 'No RSS feed for ' . $rssImport->attribute( 'name' ) );

        eZLog::write(
            " - " . $rssImport->attribute( 'name' ) . ": no RSS feeds",
            $logInfo['logName'],
            $logInfo['logDir']
        );
        $logInfo['blogKO']++;
    }
    else
    {
        $addCount = 0;
        if ( !$isQuiet )
        {
            $cli->output( 'Starting RSS importation for '.$rssImport->attribute( 'name' ) );
        }

        $xmlData = eZHTTPTool::getDataByURL( $rssSource, false, 'eZ Publish RSS Import' );
        if ( $xmlData === false )
        {
            $cli->warning( $rssImport->attribute( 'name' ).': Failed to open RSS feed file: '.$rssSource );
            eZLog::write(
                " - " . $rssImport->attribute( 'name' ) . " : Failed to open RSS feed file",
                $logInfo['logName'],
                $logInfo['logDir']
            );
            $logInfo['blogKO']++;
            continue;
        }

        // Create DomDocument from http data
        $domDocument = new DOMDocument( '1.0', 'utf-8' );
        $success = $domDocument->loadXML( $xmlData );

        if ( !$success )
        {
            $cli->warning( 'RSSImport '.$rssImport->attribute( 'name' ).': Invalid RSS document.' );
            eZLog::write(
                " - " . $rssImport->attribute( 'name' ) . " : Invalid RSS document",
                $logInfo['logName'],
                $logInfo['logDir']
            );
            $logInfo['blogKO']++;
            continue;
        }

        $root = $domDocument->documentElement;

        switch( $root->getAttribute( 'version' ) )
        {
            default:
            case '1.0':
            {
                $version = '1.0';
            } break;

            case '0.91':
            case '0.92':
            case '2.0':
            {
                $version = $root->getAttribute( 'version' );
            } break;
        }

        if ( $version !=  $rssImportDataMap['rss_version']->toString() )
        {
            $cli->warning( 'RSSImport '.$rssImport->attribute( 'name' ).': Invalid RSS version missmatch. Please reconfigure import.' );
            eZLog::write(
                $rssImport->attribute( 'name' ) . ": Invalid RSS version missmatch. Please reconfigure import",
                $logInfo['logName'],
                $logInfo['logDir']
            );
            $logInfo['blogKO']++;
            continue;
        }

        switch( $root->getAttribute( 'version' ) )
        {
            default:
            case '1.0':
            {
                eZPlanetFunctions::rssImport1( $root, $rssImport, $cli );
            } break;

            case '0.91':
            case '0.92':
            case '2.0':
            {
                eZPlanetFunctions::rssImport2( $root, $rssImport, $cli );
            } break;
        }
    }
}


/*
 * Clean and finish
 */
eZStaticCache::executeActions();

$endScript = microtime( true );
$executionTime = round( $endScript - $beginScript, 4 );

eZLog::write(
    "# END importation | " . $executionTime . "s | " . $logInfo['countTotalBlogs'] . " blog(s) : " . $logInfo['blogOK'] . " OK, " . $logInfo['blogKO'] . " KO | " . $logInfo['countTotalBillets'] . " post(s)\n",
    $logInfo['logName'],
    $logInfo['logDir']
);

?>