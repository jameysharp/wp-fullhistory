RSS (and its successor, Atom) was originally designed with news and blogs in mind, where under most circumstances only the most recent posts are of interest. However, many more things have RSS feeds today, due to major publishing platforms like WordPress enabling it on every installation, and also due to podcast tools adopting RSS as their delivery standard. In this broader context, RSS is often used for content where the full history is critical for understanding recent posts, so serving only the most recent 10 or 20 posts in the RSS feed isn't very useful.

This limitation was recognized well over a decade ago, and in 2007 the IETF published RFC5005, the "Feed Paging and Archiving" standard. Unfortunately, that standard has not yet been widely implemented.

The WordPress plugin you're currently looking at is an implementation of sections 2 and 4 of RFC5005. This plugin should work with all WordPress sites, and has no configuration options.

What this does

After installation, the only effect this plugin should have is that if you read the source of /feed/ , you should see one of these new XML tags in it:
•  <fh:complete/> 
•  <atom:link rel="prev-archive" href="…"/> 

In the latter case, the href attribute will contain a URL for another RSS feed which contains some additional posts as well as some more new XML tags. That URL is XML-escaped, so replace any &#038; with & before browsing to it.

Until feed readers and podcatchers also implement this standard, they won't see any of the archived feeds, so the only way you can tell it's working at the moment is to check the feed source as described above. However, you should find that all existing feed readers ignore the new tags and work just like they did without the plugin installed.

Status

I have done basic testing of this plugin, but I make no guarantees that installing it won't break your site. At this stage, please only test this plugin if you are prepared for things to potentially go wrong.

With that in mind, I'm eager for people to test out this plugin and let me know whether it breaks anything for you. It's pretty simple and I don't know of any bugs at this point, but that's all I can promise.

Why WordPress?

There's a catch-22 in deploying RFC5005. No matter how useful it is in theory, we only see the benefits if it's widely implemented by both publishers and feed readers. But publishers don't want to spend the time on implementation if there aren't any readers that will benefit yet, and vice versa.

So I'm short-circuiting the problem by providing an easy solution for a popular publishing platform. Eventually my hope is that this code will be merged into WordPress core, so that every installation of WordPress just automatically supports this standard.