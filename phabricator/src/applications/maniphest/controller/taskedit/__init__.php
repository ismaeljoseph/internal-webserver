<?php
/**
 * This file is automatically generated. Lint this module to rebuild it.
 * @generated
 */



phutil_require_module('phabricator', 'aphront/response/404');
phutil_require_module('phabricator', 'aphront/response/redirect');
phutil_require_module('phabricator', 'applications/files/storage/file');
phutil_require_module('phabricator', 'applications/maniphest/constants/priority');
phutil_require_module('phabricator', 'applications/maniphest/constants/status');
phutil_require_module('phabricator', 'applications/maniphest/constants/transactiontype');
phutil_require_module('phabricator', 'applications/maniphest/controller/base');
phutil_require_module('phabricator', 'applications/maniphest/editor/transaction');
phutil_require_module('phabricator', 'applications/maniphest/extensions/base');
phutil_require_module('phabricator', 'applications/maniphest/storage/task');
phutil_require_module('phabricator', 'applications/maniphest/storage/transaction');
phutil_require_module('phabricator', 'applications/metamta/contentsource/source');
phutil_require_module('phabricator', 'applications/phid/constants');
phutil_require_module('phabricator', 'applications/phid/handle/data');
phutil_require_module('phabricator', 'infrastructure/celerity/api');
phutil_require_module('phabricator', 'infrastructure/env');
phutil_require_module('phabricator', 'infrastructure/events/constant/type');
phutil_require_module('phabricator', 'infrastructure/events/event');
phutil_require_module('phabricator', 'infrastructure/javelin/api');
phutil_require_module('phabricator', 'infrastructure/javelin/markup');
phutil_require_module('phabricator', 'view/form/base');
phutil_require_module('phabricator', 'view/form/control/draganddropupload');
phutil_require_module('phabricator', 'view/form/control/markup');
phutil_require_module('phabricator', 'view/form/control/select');
phutil_require_module('phabricator', 'view/form/control/static');
phutil_require_module('phabricator', 'view/form/control/submit');
phutil_require_module('phabricator', 'view/form/control/textarea');
phutil_require_module('phabricator', 'view/form/control/tokenizer');
phutil_require_module('phabricator', 'view/form/error');
phutil_require_module('phabricator', 'view/layout/panel');

phutil_require_module('phutil', 'events/engine');
phutil_require_module('phutil', 'markup');
phutil_require_module('phutil', 'utils');


phutil_require_source('ManiphestTaskEditController.php');
