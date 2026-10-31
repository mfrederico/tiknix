Google Analytics 4, one Measurement ID per site: press Set it up on this plugin's page. Class `app\concepts\googleanalytics\Tag` puts Google's tag on
every page, whichever layout drew it, only while the site is asking and only for a visitor who said yes to Statistics. Admins are not counted.
Never write a gtag or Tag Manager snippet by hand: it would load before a yes, and the consent plugin's policy would block it. To count an
event from a page: `if (window.gtag) gtag('event', 'signup')` (`gtag` exists only for a visitor who said yes). From PHP, before a redirect:
`\app\concepts\googleanalytics\Tag::event('project_created')` (check `Concepts::has('googleanalytics')` first): it goes out on the visitor's next page, only after a yes.
Never put a name, an email or anything about a person in an event. `sign_up` is sent by itself.
