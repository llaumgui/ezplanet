<!-- BLOCK: START -->
<div class="block-type-ezfluxbb-stats">

<div class="border-box block-style2-box-outside">
<div class="border-tl"><div class="border-tr"><div class="border-tc"></div></div></div>
<div class="border-ml"><div class="border-mr"><div class="border-mc">
<div class="border-content">

<!-- BLOCK BORDER INSIDE: START -->

<div class="border-box block-style2-box-inside">
<div class="border-tl"><div class="border-tr"><div class="border-tc"></div></div></div>
<div class="border-ml"><div class="border-mr"><div class="border-mc">
<div class="border-content">

<!-- BLOCK CONTENT: START -->
    <h2>{$block.name|wash()}</h2>
{def $people_class = ezini( 'PlanetSettings', 'BloggerClassIdentifier', 'ezplanet.ini' )
     $link_attribute = ezini( 'PlanetSettings', 'BloggerLocationAttributeIdentifier', 'ezplanet.ini' )
     $rss_attribute = ezini( 'PlanetSettings', 'BloggerRSSLocationAttributeIdentifier', 'ezplanet.ini' )
     $peoples = fetch(content, tree, hash(
    parent_node_id, ezini( 'PlanetSettings', 'BloggerParentNodeID', 'ezplanet.ini' ),
    class_filter_type, 'include',
    class_filter_array, array( $people_class ),
    sort_by, array( 'name', true() ),
) )
}
    {if $peoples}<ul>
    {foreach $peoples as $people}
        {if and( $people.data_map[$rss_attribute].has_content, $people.data_map[$link_attribute].has_content )}
        <li><a href="{$people.data_map[$rss_attribute].content}"><img src={"feed.png"|ezimage()} alt="RSS" /></a> <a href="{$people.data_map[$link_attribute].content}">{$people.name|wash()}</a></li>
        {/if}
    {/foreach}
    </ul>{/if}
{undef $people_class $link_attribute $rss_attribute $peoples}
<!-- BLOCK CONTENT: END -->

</div>
</div></div></div>
<div class="border-bl"><div class="border-br"><div class="border-bc"></div></div></div>
</div>

<!-- BLOCK BORDER INSIDE: END -->


</div>
</div></div></div>
<div class="border-bl"><div class="border-br"><div class="border-bc"></div></div></div>
</div>

</div>
<!-- BLOCK: END -->