<?php
/**
 * File containing the eZPlanet cronjobs
 * Based on rssimport cronjob
 *
 * @version //autogentag//
 * @package EZPlanet
 * @copyright Copyright (C) 2008-2012 Guillaume Kulakowski and contributors
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

//For ezUser, we would make this the ezUser class id but otherwise just pick and choose.

//Init ezplanet
$planet = eZPlanet::instance();

// Loop through all configured and active rss imports. If something goes wrong while processing them, continue to next import
foreach ( $planet->getBloggers() as $rssImport )
{
    // Get RSSImport object
    if ( ( $rssSource = $planet->getRssSourceFromBlogger( $rssImport ) ) === false )
    {
        continue;
    }
    $addCount = 0;

    $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Starting.' );

    $xmlData = eZHTTPTool::getDataByURL( $rssSource, false, 'eZ Publish RSS Import' );
    if ( $xmlData === false )
    {
        $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Failed to open RSS feed file: '.$rssSource );
        eZLog::write( "\t".$rssImport->attribute( 'name' ).': Failed to open RSS feed file: '.$rssSource, 'ezplanet.log' );
        $planet->blogKO++;
        continue;
    }

    // Create DomDocument from http data
    $domDocument = new DOMDocument( '1.0', 'utf-8' );
    $success = $domDocument->loadXML( $xmlData );

    if ( !$success )
    {
        $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Invalid RSS document.' );
        eZLog::write( "\t".$rssImport->attribute( 'name' ).': Invalid RSS document.', 'ezplanet.log' );
        $planet->blogKO++;
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

    if ( $version != $planet->getRssVersionFromBlogger( $rssImport ) )
    {
        $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Invalid RSS version missmatch. Please reconfigure import.' );
        eZLog::write( "\t".$rssImport->attribute( 'name' ).': Invalid RSS version missmatch. Please reconfigure import.', 'ezplanet.log' );
        $planet->blogKO++;
        continue;
    }

    switch( $root->getAttribute( 'version' ) )
    {
        default:
        case '1.0':
        {
            rssImport1( $root, $rssImport, $cli );
        } break;

        case '0.91':
        case '0.92':
        case '2.0':
        {
            rssImport2( $root, $rssImport, $cli );
        } break;
    }

}

eZStaticCache::executeActions();
eZLog::write( "# END importation | {$planet->getExecutionTime()}s | {$planet->countBlogs} blog(s) : {$planet->blogOK} OK, {$planet->blogKO} KO | {$planet->countPosts} post(s)\n", 'ezplanet.log' );

/**
 * Parse RSS 1.0 feed
 *
 * @param DOMElement $root DOM root node
 * @param eZContentObjectTreeNode $rssImport RSS Import item
 * @param eZCLI $cli
 */
function rssImport1( $root, $rssImport, $cli )
{
    $addCount = 0;

    // Get all items in rss feed
    $itemArray = $root->getElementsByTagName( 'item' );
    $channel = $root->getElementsByTagName( 'channel' )->item( 0 );

    // Loop through all items in RSS feed
    foreach ( $itemArray as $item )
    {
        $addCount += importRSSItem( $item, $rssImport, $cli, $channel );
    }

    $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': End. '.$addCount.' objects added' );
    eZLog::write( $rssImport->attribute( 'name' ).' (RSS1): End. '.$addCount.' objects added', 'ezplanet.log' );
    $planet = eZPlanet::instance();
    $planet->countPosts += $addCount;
    $planet->blogOK++;
}

/**
 * Parse RSS 2.0 feed
 *
 * @param DOMElement $root DOM root node
 * @param eZContentObjectTreeNode $rssImport RSS Import item
 * @param eZCLI $cli
 */
function rssImport2( $root, $rssImport, $cli )
{
    $addCount = 0;

    // Get all items in rss feed
    $channel = $root->getElementsByTagName( 'channel' )->item( 0 );

    // Loop through all items in RSS feed
    foreach ( $channel->getElementsByTagName( 'item' ) as $item )
    {
        $addCount += importRSSItem( $item, $rssImport, $cli, $channel );
    }

    $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': End. '.$addCount.' objects added' );
    eZLog::write( $rssImport->attribute( 'name' ).' (RSS2): End. '.$addCount.' objects added', 'ezplanet.log' );
    $planet = eZPlanet::instance();
    $planet->countPosts += $addCount;
    $planet->blogOK++;
}

/**
 * Import specifiec rss item into content tree
 *
 * @param DOMElement $item RSS item xml element
 * @param eZContentObjectTreeNode $rssImport RSS Import item
 * @param eZCLI $cli
 * @param DOMElement $channel
 *
 * @return 1 if object added, 0 if not
*/
function importRSSItem( $item, $rssImport, $cli, $channel )
{
    $rssImportID = $rssImport->attribute( 'id' );
    $rssOwnerID = $rssImport->object()->attribute( 'id' ); // Get owner user id
    $parentContentObjectTreeNode = $rssImport; // Get parent treenode object

    if ( $parentContentObjectTreeNode == null )
    {
        $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Destination tree node seems to be unavailable' );
        return 0;
    }

    $parentContentObject = $parentContentObjectTreeNode->attribute( 'object' ); // Get parent content object
    $titleElement = $item->getElementsByTagName( 'title' )->item( 0 );
    $title = is_object( $titleElement ) ? $titleElement->textContent : '';

    // Test for link or guid as unique identifier
    $link = $item->getElementsByTagName( 'link' )->item( 0 );
    $guid = $item->getElementsByTagName( 'guid' )->item( 0 );
    $rssId = '';
    if ( $link->textContent )
    {
        $rssId = $link->textContent;
    }
    elseif ( $guid->textContent )
    {
        $rssId = $guid->textContent;
    }
    else
    {
        $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Item has no unique identifier. RSS guid or link missing.' );
        return 0;
    }
    $md5Sum = md5( $rssId );

    // Try to fetch RSSImport object with md5 sum matching link.
    $existingObject = eZPersistentObject::fetchObject( eZContentObject::definition(), null,
                                                       array( 'remote_id' => 'RSSImport_'.$rssImportID.'_'.$md5Sum ) );

    // if object exists, continue to next import item
    if ( $existingObject != null )
    {
        $cli->output( 'RSSImport ' . $rssImport->attribute( 'name' ) . ': Object ( ' . $existingObject->attribute( 'id' ) . ' ) with ID: "' . $rssId . '" already exists' );
        unset( $existingObject ); // delete object to preserve memory
        return 0;
    }

    // Fetch class, and create ezcontentobject from it.
    $planet = eZPlanet::instance();
    $contentClass = $planet->getPostContentClass();

    // Instantiate the object with user $rssOwnerID and use section id from parent. And store it.
    $contentObject = $contentClass->instantiate( $rssOwnerID, $parentContentObject->attribute( 'section_id' ) );

    $db = eZDB::instance();
    $db->begin();
    $contentObject->store();
    $contentObjectID = $contentObject->attribute( 'id' );

    // Create node assignment
    $nodeAssignment = eZNodeAssignment::create( array( 'contentobject_id' => $contentObjectID,
                                                       'contentobject_version' => $contentObject->attribute( 'current_version' ),
                                                       'is_main' => 1,
                                                       'parent_node' => $parentContentObjectTreeNode->attribute( 'node_id' ) ) );
    $nodeAssignment->store();

    $version = $contentObject->version( 1 );
    $version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
    $version->store();

    // Get object attributes, and set their values and store them.
    $dataMap = $contentObject->dataMap();
    $importDescription = $planet->getImportDescription();

    // Set content object attribute values.
    $classAttributeList = $contentClass->fetchAttributes();
    foreach( $classAttributeList as $classAttribute )
    {
        $classAttributeID = $classAttribute->attribute( 'identifier' );
        if ( isset( $importDescription['class_attributes'][$classAttributeID] ) )
        {
            if ( $importDescription['class_attributes'][$classAttributeID] == '-1' )
            {
                continue;
            }

            $importDescriptionArray = explode( ' - ', $importDescription['class_attributes'][$classAttributeID] );
            if ( count( $importDescriptionArray ) < 1 )
            {
                $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Invalid import definition. Please redit.' );
                break;
            }

            $elementType = $importDescriptionArray[0];
            array_shift( $importDescriptionArray );
            switch( $elementType )
            {
                case 'item':
                {
                    setObjectAttributeValue( $dataMap[$classAttribute->attribute( 'identifier' )],
                                             recursiveFindRSSElementValue( $importDescriptionArray,
                                                                           $item ) );
                } break;

                case 'channel':
                {
                    setObjectAttributeValue( $dataMap[$classAttribute->attribute( 'identifier' )],
                                             recursiveFindRSSElementValue( $importDescriptionArray,
                                                                           $channel ) );
                } break;
            }
        }
    }

    $contentObject->setAttribute( 'remote_id', 'RSSImport_'.$rssImportID.'_'. $md5Sum );
    $contentObject->store();
    $db->commit();

    // Publish new object. The user id is sent to make sure any workflow
    // requiring the user id has access to it.
    $operationResult = eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $contentObject->attribute( 'id' ),
                                                                                 'version' => 1,
                                                                                 'user_id' => $rssOwnerID ) );

    if ( !isset( $operationResult['status'] ) || $operationResult['status'] != eZModuleOperationInfo::STATUS_CONTINUE )
    {
        if ( isset( $operationResult['result'] ) && isset( $operationResult['result']['content'] ) )
            $failReason = $operationResult['result']['content'];
        else
            $failReason = "unknown error";
        $cli->error( "Publishing failed: $failReason" );
        unset( $failReason );
    }

    $db->begin();
    unset( $contentObject );
    unset( $version );
    $contentObject = eZContentObject::fetch( $contentObjectID );
    $version = $contentObject->attribute( 'current' );
    // Set object Attributes like modified and published timestamps
    $objectAttributeDescription = $importDescription['object_attributes'];
    foreach( $objectAttributeDescription as $identifier => $objectAttributeDefinition )
    {
        if ( $objectAttributeDefinition == '-1' )
        {
            continue;
        }

        $importDescriptionArray = explode( ' - ', $objectAttributeDefinition );

        $elementType = $importDescriptionArray[0];
        array_shift( $importDescriptionArray );
        switch( $elementType )
        {
            default:
            case 'item':
            {
                $domNode = $item;
            } break;

            case 'channel':
            {
                $domNode = $channel;
            } break;
        }

        switch( $identifier )
        {
            case 'modified':
            {
                $dateTime = recursiveFindRSSElementValue( $importDescriptionArray,
                                                          $domNode );
                if ( !$dateTime )
                {
                    break;
                }
                $contentObject->setAttribute( $identifier, strtotime( $dateTime ) );
                $version->setAttribute( $identifier, strtotime( $dateTime ) );
            } break;

            case 'published':
            {
                $dateTime = recursiveFindRSSElementValue( $importDescriptionArray,
                                                          $domNode );
                if ( !$dateTime )
                {
                    break;
                }
                $contentObject->setAttribute( $identifier, strtotime( $dateTime ) );
                $version->setAttribute( 'created', strtotime( $dateTime ) );
            } break;
        }
    }
    $version->store();
    $contentObject->store();
    $db->commit();

    $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Object created; ' . $title );

    return 1;
}

/**
 * Find recursive RSS element value
 *
 * @param array $importDescriptionArray
 * @param unknown_type $xmlDomNode
 */
function recursiveFindRSSElementValue( $importDescriptionArray, $xmlDomNode )
{
    if ( !is_array( $importDescriptionArray ) )
    {
        return false;
    }

    $valueType = $importDescriptionArray[0];
    array_shift( $importDescriptionArray );
    switch( $valueType )
    {
        case 'elements':
        {
            if ( count( $importDescriptionArray ) == 1 )
            {
                $element = $xmlDomNode->getElementsByTagName( $importDescriptionArray[0] )->item( 0 );

                $resultText = is_object( $element ) ? $element->textContent : false;
                return $resultText;
            }
            else
            {
                $elementName = $importDescriptionArray[0];
                array_shift( $importDescriptionArray );
                return recursiveFindRSSElementValue( $importDescriptionArray, $xmlDomNode->getElementsByTagName( $elementName )->item( 0 ) );
            }
        }

        case 'attributes':
        {
            return $xmlDomNode->getAttribute( $importDescriptionArray[0] );
        } break;
    }
}

/**
 * Set object attribute value
 *
 * @param eZContentObjectAttribute $objectAttribute
 * @param string $value
 */
function setObjectAttributeValue( $objectAttribute, $value )
{
    if ( $value === false )
    {
        return;
    }

    $dataType = $objectAttribute->attribute( 'data_type_string' );
    switch( $dataType )
    {
        case 'ezxmltext':
        {
            setEZXMLAttribute( $objectAttribute, $value );
        } break;

        case 'ezurl':
        {
            $objectAttribute->setContent( $value );
        } break;

        case 'ezkeyword':
        {
            $keyword = new eZKeyword();
            $keyword->initializeKeyword( $value );
            $objectAttribute->setContent( $keyword );
        } break;

        case 'eztext':
        {
            eZPlanet::setEZTXTAttribute( $objectAttribute, $value );
        } break;

        case 'ezdate':
        {
            $timestamp = strtotime( $value );
            if ( $timestamp )
                $objectAttribute->setAttribute( 'data_int', $timestamp );
        } break;

        case 'ezdatetime':
        {
            $objectAttribute->setAttribute( 'data_int', strtotime( $value ) );
        } break;

        default:
        {
            $objectAttribute->setAttribute( 'data_text', $value );
        } break;
    }

    $objectAttribute->store();
}

/**
 * Set EZXML attribute
 *
 * @param unknown_type $attribute
 * @param unknown_type $attributeValue
 * @param unknown_type $link
 */
function setEZXMLAttribute( $attribute, $attributeValue, $link = false )
{
    $contentObjectID = $attribute->attribute( "contentobject_id" );
    $parser = new eZSimplifiedXMLInputParser( $contentObjectID, false, 0, false );

    $attributeValue = str_replace( "\r", '', $attributeValue );
    $attributeValue = str_replace( "\n", '', $attributeValue );
    $attributeValue = str_replace( "\t", ' ', $attributeValue );

    $document = $parser->process( $attributeValue );
    if ( !is_object( $document ) )
    {
        $cli = eZCLI::instance();
        $cli->output( 'Error in xml parsing' );
        return;
    }
    $domString = eZXMLTextType::domString( $document );

    $attribute->setAttribute( 'data_text', $domString );
    $attribute->store();
}

?>