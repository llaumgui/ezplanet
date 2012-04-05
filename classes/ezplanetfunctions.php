<?php
/**
 * File containing the eZPlanetFunctions class
 *
 * @version //autogentag//
 * @package EZPlanet
 * @copyright Copyright (C) 2008-2012 Guillaume Kulakowski and contributors
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

/**
 * The eZGauffr class provide function used by RSS importation in eZPlanet
 *
 * @package EZPlanet
 * @version //autogentag//
 */
class eZPlanetFunctions
{

    /**
     * Clean field description in RSS feed.
     *
     * @param sting $str string
     */
    public static function cleanRSSDescription( &$str )
    {
        $patterns[] = '#<img src="/(.+?)" alt="(.+?)" />#is';
        $patterns[] = '#<img src="/(.+?)" alt="" />#is';
        $patterns[] = '/’/';

        $replace[] = '$2';
        $replace[] = '';
        $replace[] = '';

        $str = preg_replace( $patterns,  $replace, $str );
    }



    /**
     * Set the EZTXT attribute
     *
     * @param unknown_type $attribute
     * @param unknown_type $attributeValue
     * @param unknown_type $link
     */
    public static function setEZTXTAttribute( $attribute, $attributeValue, $link = false )
    {
        self::cleanRSSDescription( $attributeValue );

        $ini = eZINI::instance();

        if( class_exists( 'eZTidy' )
         && ( in_array( 'eztidy', $ini->variable( "ExtensionSettings", "ActiveExtensions" ) )
              || in_array( 'eztidy', $ini->variable( "ExtensionSettings", "ActiveAccessExtensions" ) )) )
        {
            $tidy = eZTidy::instance( 'eZPlanet' );
            $attributeValue = $tidy->tidyCleaner( $attributeValue );
        }

        /* Et hop ! Dans eZ */
        $attribute->setAttribute( 'data_text', $attributeValue );
        $attribute->store();
    }



    /**
     * Parse RSS 1.0 feed
     *
     * @param unknown_type $root
     * @param unknown_type $rssImport
     * @param eZCli $cli
     */
    function rssImport1( $root, $rssImport, $cli )
    {
        global $isQuiet, $logInfo;

        $addCount = 0;

        // Get all items in rss feed
        $itemArray = $root->getElementsByTagName( 'item' );
        $channel = $root->getElementsByTagName( 'channel' )->item( 0 );

        // Loop through all items in RSS feed
        foreach ( $itemArray as $item )
        {
            $addCount += self::importRSSItem( $item, $rssImport, $cli, $channel );
        }

        if ( !$isQuiet )
        {
            $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': End. '.$addCount.' objects added' );
        }
        eZLog::write(
            $rssImport->attribute( 'name' ) . " (RSS1) : $addCount objects added",
            $logInfo['logName'],
            $logInfo['logDir']
        );
        $logInfo['countTotalBillets'] += $addCount;
        $logInfo['blogOK']++;
    }



    /**
     * Parse RSS 2.0 feed
     *
     * @param unknown_type $root
     * @param unknown_type $rssImport
     * @param eZCli $cli
     */
    function rssImport2( $root, $rssImport, $cli )
    {
        global $isQuiet, $logInfo;

        $addCount = 0;

        // Get all items in rss feed
        $channel = $root->getElementsByTagName( 'channel' )->item( 0 );

        // Loop through all items in RSS feed
        foreach ( $channel->getElementsByTagName( 'item' ) as $item )
        {
            $addCount += self::importRSSItem( $item, $rssImport, $cli, $channel );
        }

        if ( !$isQuiet )
        {
            $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': End. '.$addCount.' objects added' );
        }
        eZLog::write(
            $rssImport->attribute( 'name' ) . " (RSS2) : $addCount objects added",
            $logInfo['logName'],
            $logInfo['logDir']
        );
        $logInfo['countTotalBillets'] += $addCount;
        $logInfo['blogOK']++;
    }



    /**
     * Import specifiec rss item into content tree
     *
     * @param unknown_type $item
     * @param unknown_type $rssImport
     * @param eZCli $cli
     * @param unknown_type $channel
     *
     * @return 1 if object added, 0 if not
     */
    function importRSSItem( $item, $rssImport, $cli, $channel )
    {
        global $isQuiet;

        $iniPlanet = eZINI::instance( "ezplanet.ini" );

        $rssImportID = $rssImport->attribute( 'id' );
        $rssOwnerID = $rssImport->object()->ID; // Get owner user id
        $parentContentObjectTreeNode = $rssImport;

        if ( $parentContentObjectTreeNode == null )
        {
            if ( !$isQuiet )
            {
                $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Destination tree node seems to be unavailable' );
            }
            return 0;
        }

        $parentContentObject = $parentContentObjectTreeNode->attribute( 'object' ); // Get parent content object
        $titleElement = $item->getElementsByTagName( 'title' )->item( 0 );
        $title = is_object( $titleElement ) ? $titleElement->textContent : '';

        // Test for link or guid as unique identifier
        $link = $item->getElementsByTagName( 'link' )->item( 0 );
        $guid = $item->getElementsByTagName( 'guid' )->item( 0 );
        if ( $link->textContent )
        {
            $md5Sum = md5( $link->textContent );
        }
        elseif ( $guid->textContent )
        {
            $md5Sum = md5( $guid->textContent );
        }
        else
        {
            if ( !$isQuiet )
            {
                $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Item has no unique identifier. RSS guid or link missing.' );
            }
            return 0;
        }

        // Try to fetch RSSImport object with md5 sum matching link.
        $existingObject = eZPersistentObject::fetchObject( eZContentObject::definition(), null,
                                                           array( 'remote_id' => 'RSSImport__'.$md5Sum ) );

        // if object exists, continue to next import item
        if ( $existingObject != null )
        {
            if ( !$isQuiet )
            {
                $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Object ( ' . $existingObject->attribute( 'id' ) . ' ) with URL: ' . $link->textContent . ' already exists' );
            }
            unset( $existingObject ); // delete object to preserve memory
            return 0;
        }

        // Fetch class, and create ezcontentobject from it.
        $contentClass = eZContentClass::fetchByIdentifier( $iniPlanet->variable( "BlogPostClass", "ClassIdentifier" )  );
        if ( ! $contentClass instanceof eZContentClass )
        {
            $cli->error( 'There is no eZContentClass with "' . $iniPlanet->variable( "BlogPostClass", "ClassIdentifier" ) . '" identifier' );
            eZExecution::cleanExit();
        }

        // Instantiate the object with user $rssOwnerID and use section id from parent. And store it.
        $contentObject = $contentClass->instantiate( $rssOwnerID, $parentContentObject->attribute( 'section_id' ) );

        $db = eZDB::instance();
        $db->begin();
        $contentObject->store();
        $contentObjectID = $contentObject->attribute( 'id' );

        // Create node assignment
        $nodeAssignment = eZNodeAssignment::create( array(
            'contentobject_id' => $contentObjectID,
            'contentobject_version' => $contentObject->attribute( 'current_version' ),
            'is_main' => 1,
            'parent_node' => $parentContentObjectTreeNode->attribute( 'node_id' ) )
        );
        $nodeAssignment->store();

        $version = $contentObject->version( 1 );
        $version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
        $version->store();

        // Get object attributes, and set their values and store them.
        $dataMap = $contentObject->dataMap();
        $importDescription = $iniPlanet->variable( "BlogPostClass", "ImportDescription" );
        $importDescription['class_attributes'] = $iniPlanet->variable( "BlogPostClass", "ClassAttributes" );

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
                        self::setObjectAttributeValue( $dataMap[$classAttribute->attribute( 'identifier' )],
                                                 self::recursiveFindRSSElementValue( $importDescriptionArray,
                                                                               $item ) );
                    } break;

                    case 'channel':
                    {
                        self::setObjectAttributeValue( $dataMap[$classAttribute->attribute( 'identifier' )],
                                                 self::recursiveFindRSSElementValue( $importDescriptionArray,
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
            {
                $failReason = $operationResult['result']['content'];
            }
            else
            {
                $failReason = "unknown error";
            }
            $cli->error( "Publishing failed: $failReason" );
            unset( $failReason );
        }

        $db->begin();
        unset( $contentObject );
        unset( $version );
        $contentObject = eZContentObject::fetch( $contentObjectID );
        $version = $contentObject->attribute( 'current' );
        // Set object Attributes like modified and published timestamps
        $objectAttributeDescription = $iniPlanet->variable( "BlogPostClass", "OjectAttributes" );
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
                    $dateTime = self::recursiveFindRSSElementValue( $importDescriptionArray,
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
                    $dateTime = self::recursiveFindRSSElementValue( $importDescriptionArray,
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

        if ( !$isQuiet )
        {
            $cli->output( 'RSSImport '.$rssImport->attribute( 'name' ).': Object created; ' . $title );
        }

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
                    $resultArray = array();
                    if ( $xmlDomNode->getElementsByTagName( $importDescriptionArray[0] )->length < 1 )
                    {
                        return false;
                    }

                    for ( $item = 0; $item < $xmlDomNode->getElementsByTagName( $importDescriptionArray[0] )->length; $item++ )
                    {
                        $element = $xmlDomNode->getElementsByTagName( $importDescriptionArray[0] )->item( $item );
                        if ( is_object( $element ) )
                        {
                            $resultArray[] = $element->textContent;
                        }
                    }
                    if ( !$resultArray )
                    {
                        return false;
                    }

                    return implode( ',', $resultArray );
                }
                else
                {
                    $elementName = $importDescriptionArray[0];
                    array_shift( $importDescriptionArray );
                    return self::recursiveFindRSSElementValue( $importDescriptionArray, $xmlDomNode->getElementsByTagName( $elementName )->item( 0 ) );
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
     * @param unknown_type $objectAttribute
     * @param unknown_type $value
     */
    static function  setObjectAttributeValue( $objectAttribute, $value )
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
                self::setEZXMLAttribute( $objectAttribute, $value );
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
                self::setEZTXTAttribute( $objectAttribute, $value );
            } break;

            case 'ezdate':
            {
                $timestamp = strtotime( $value );
                if ( $timestamp )
                {
                    $objectAttribute->setAttribute( 'data_int', $timestamp );
                }
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

}

?>