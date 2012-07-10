<?php
/**
 * File containing the eZPlanet class
 *
 * @version //autogentag//
 * @package EZPlanet
 * @copyright Copyright (C) 2008-2012 Guillaume Kulakowski and contributors
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

/**
 * The eZPlanet classe
 *
 * @package EZPlanet
 * @version //autogentag//
 */
class eZPlanet
{
    /**
     * Script begin microtime
     * @var integer
     */
    public $beginScript;
    /**
     * Count posts fetched
     * @var integer
     */
    public $countPosts;
    /**
     * Count blogs fetched
     * @var integer
     */
    public $countBlogs;
    /**
     * Number of blogs OK
     * @var integer
     */
    public $blogOK;
    /**
     * Number of blogs return a error
     * @var integer
     */
    public $blogKO;
    /**
     * Blogger class identifier
     * @var string
     */
    private $bloggerClassIdentifier;
    /**
     * Blogger RSS URL location attribut identifier
     * @var string
     */
    private $bloggerRSSLocationAttributeIdentifier;
    /**
     * Blogger parent_node_id
     * @var integer
     */
    private $bloggerParentNodeId;





    /**
     * Constructor
     */
    private function eZPlanet()
    {
        $this->beginScript = microtime( true );

        $this->countPosts = 0;
        $this->countBlogs = 0;
        $this->blogOK = 0;
        $this->blogKO = 0;

        $ini = eZINI::instance( "ezplanet.ini" );
        $this->bloggerClassIdentifier = $ini->variable( "PlanetSettings", "BloggerClassIdentifier" );
        $this->bloggerRSSLocationAttributeIdentifier =  $ini->variable( "PlanetSettings", "BloggerRSSLocationAttributeIdentifier" );
        $this->bloggerParentNodeId = $ini->variable( "PlanetSettings", "BloggerParentNodeID" );

    }


    /**
     * Singleton
     *
     * @return eZPlanet
     */
    static function instance()
    {
        if ( !isset( $GLOBALS['eZPlanetInstance'] ) or
             !( $GLOBALS['eZPlanetInstance'] instanceof eZPlanet ) )
        {
            $GLOBALS['eZPlanetInstance'] = new eZPlanet();
        }
        return $GLOBALS['eZPlanetInstance'];
    }


    /**
     * Get bloggers list
     */
    public function getBloggers()
    {
        $cli = eZCLI::instance();

        /*
         * Feth blogger eZContentClass
         */
        $bloggerContentClass = eZFunctionHandler::execute( 'content', 'class', array(
            'class_id' => $this->bloggerClassIdentifier,
        ) );
        if ( ! $bloggerContentClass instanceof eZContentClass )
        {
            $cli->error( 'There is no eZContentClass with "' . $this->bloggerClassIdentifier . '" identifier !' );
            return array();
        }
        $cli->output( $cli->stylize( 'cyan', 'Use "' . $bloggerContentClass->name(). '" class' ) );


        /*
         * Feth parent eZContentObjectTreeNode
         */

        $bloggerParentNode= eZFunctionHandler::execute( 'content', 'node', array(
            'node_id' => $this->bloggerParentNodeId,
        ) );
        if ( ! $bloggerParentNode instanceof eZContentObjectTreeNode )
        {
            $cli->error( 'There is no eZContentObjectTreeNode with node_id=' . $this->bloggerParentNodeId );
            return array();
        }
        $cli->output( $cli->stylize( 'cyan', 'Use "' . $bloggerParentNode->getName(). '" parent node (' . $this->bloggerParentNodeId . ')' ) );


        /*
         * Feth bloggers eZContentObjectTreeNode
         */
        $bloggersNode= eZFunctionHandler::execute( 'content', 'tree', array(
            'parent_node_id' => $this->bloggerParentNodeId,
            'class_filter_type' => 'include',
            'class_filter_array' => array( $this->bloggerClassIdentifier )
        ) );
        if ( !$bloggersNode )
        {
            $cli->error( 'There is no blogger (' . $bloggerContentClass->name() .') in "' . $bloggerParentNode->getName() . '" (' . $this->bloggerParentNodeId . ')' );
            return array();
        }
        $this->countBlogs = count( $bloggersNode );
        $cli->output( $cli->stylize( 'cyan', 'Find ' . $this->countBlogs . ' blogger(s)' ) );

        return $bloggersNode;
    }


    /**
     * Get RSS location from a blogger.
     *
     * @param eZContentObjectTreeNode $blogger
     * @return string
     */
    public function getRssSourceFromBlogger ( eZContentObjectTreeNode $blogger )
    {
        $dataMap = $blogger->dataMap();
        $rssSource = $dataMap[$this->bloggerRSSLocationAttributeIdentifier]->content();

        if( empty( $rssSource ) )
        {
            $cli->warning( 'No RSS feed for ' . $blogger->attribute( 'name' ) );
            eZLog::write( "\t" . $blogger->attribute( 'name' ) . ': no RSS feeds', 'ezplanet.log' );
            $this->blogKO++;
        }
        return $rssSource;
    }


    /**
     * Get RSS version from a blogger.
     *
     * @param eZContentObjectTreeNode $blogger
     * @return string
     */
    public function getRssVersionFromBlogger( eZContentObjectTreeNode $blogger )
    {
        $dataMap = $blogger->dataMap();
        return $dataMap['rss_version']->toString();
    }


    /**
     * Get content class for post storage
     *
     * @return eZContentClass
     */
    public function getPostContentClass()
    {
        $ini = eZINI::instance( "ezplanet.ini" );

        // Fetch class, and create ezcontentobject from it.
        $contentClass = eZContentClass::fetchByIdentifier( $ini->variable( "BlogPostClass", "ClassIdentifier" )  );
        if ( ! $contentClass instanceof eZContentClass )
        {
            $cli->error( 'There is no eZContentClass with "' . $ini->variable( "BlogPostClass", "ClassIdentifier" ) . '" identifier' );
            eZExecution::cleanExit();
        }
        return $contentClass;
    }


    /**
     * Get RSS import description
     *
     * @return array
     */
    public function getImportDescription()
    {
        $ini = eZINI::instance( "ezplanet.ini" );

        $importDescription = $ini->variable( "BlogPostClass", "ImportDescription" );
        $importDescription['class_attributes'] = $ini->variable( "BlogPostClass", "ClassAttributes" );
        $importDescription['object_attributes'] = $ini->variable( "BlogPostClass", "OjectAttributes" );

        return $importDescription;
    }


    /**
     * Get the script execution time.
     *
     * @return integer
     */
    public function getExecutionTime()
    {
        return round( ( microtime( true ) - $this->beginScript ), 4 );
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

        if( class_exists( 'eZTidy' ) &&
            ( in_array( 'eztidy', $ini->variable( "ExtensionSettings", "ActiveExtensions" ) ) ||
            in_array( 'eztidy', $ini->variable( "ExtensionSettings", "ActiveAccessExtensions" ) ) ) ) {
            $tidy = eZTidy::instance( 'eZPlanet' );
            $attributeValue = $tidy->tidyCleaner( $attributeValue );
        }

        // hop ! In eZ
        $attribute->setAttribute( 'data_text', $attributeValue );
        $attribute->store();
    }


    /**
     * Clean field description in RSS feed.
     *
     * @param sting $string string
     */
    public static function cleanRSSDescription( &$string )
    {
        $patterns[] = '#<img src="/(.+?)" alt="(.+?)" />#is';
        $patterns[] = '#<img src="/(.+?)" alt="" />#is';
        $patterns[] = '/’/';

        $replace[] = '$2';
        $replace[] = '';
        $replace[] = '';

        $string = preg_replace( $patterns,  $replace, $string );
    }

}

?>