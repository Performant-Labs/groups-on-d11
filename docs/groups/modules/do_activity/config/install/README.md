# do_activity/config/install/

These 24 YAML files are **duplicated** in `docs/groups/config/` for the
CI config-import path (drush config:import from config/sync/ does not fire
newly-enabled modules' config/install/). Edits must be applied to both
locations. Ownership of both duplicates lives with #116.

Longer-term (post-POC): move canonically to docs/groups/config/ and have
kernel tests import from a fixture directory instead of installConfig().
Filed as a follow-up in the story's decision log.
