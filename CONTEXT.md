# CONTEXT — neo_favicon

Terms specific to this module. General Drupal vocabulary (config entity, event subscriber, settings
form) does not belong here.

## The favicon package

**Favicon package** — the zip realfavicongenerator.net produces, uploaded on the favicon settings
form as a config file and unpacked into one directory beneath the public files, from which this
module links the icons into every page's head. It is accepted only with a `.zip` extension. That
directory is never emptied ahead of an extraction: the zip extractor in `neo_config_file` unpacks
elsewhere and replaces it once the whole package is on disk, so a package that will not open is
logged as a warning and leaves the installed favicons exactly where they were. _Avoid:_ "the
favicon zip", "the icon package", "the favicon archive".
