<?php /* #?ini charset="utf-8"?

[PlanetSettings]

# User class used for extract planet URLs
BloggerClassIdentifier=user

# Attribute to find RSS feed URL
BloggerRSSLocationAttributeIdentifier=location_rss
BloggerLocationAttributeIdentifier=location_blog

# Parent node ID where find bloggers
#BloggerParentNodeID=2


[BlogPostClass]
# Settings for the blog post class

ClassIdentifier=planet_blogpost
ImportDescription[rss_version]=2.0

ClassAttributes[]
ClassAttributes[name]=item - elements - title
ClassAttributes[description]=item - elements - description
ClassAttributes[author]=item - elements - author
ClassAttributes[location]=item - elements - link
ClassAttributes[category]=item - elements - category
ClassAttributes[guid]=item - elements - guid

OjectAttributes[]
OjectAttributes[published]=item - elements - pubDate
OjectAttributes[modified]=item - elements - pubDate

*/ ?>