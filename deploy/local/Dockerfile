FROM elabftw/elabimg:latest

WORKDIR /elabftw

COPY apps/elabftw/src/Controllers/ProjectsController.php /elabftw/src/Controllers/ProjectsController.php
COPY apps/elabftw/src/Controllers/SamplesController.php /elabftw/src/Controllers/SamplesController.php
COPY apps/elabftw/src/Controllers/ReviewsController.php /elabftw/src/Controllers/ReviewsController.php
COPY apps/elabftw/src/Controllers/ExperimentsController.php /elabftw/src/Controllers/ExperimentsController.php
COPY apps/elabftw/src/Controllers/InventoryController.php /elabftw/src/Controllers/InventoryController.php
COPY apps/elabftw/src/Controllers/DatabaseController.php /elabftw/src/Controllers/DatabaseController.php
COPY apps/elabftw/src/Controllers/DashboardController.php /elabftw/src/Controllers/DashboardController.php
COPY apps/elabftw/src/Controllers/AssayCatalogController.php /elabftw/src/Controllers/AssayCatalogController.php

COPY apps/elabftw/src/Models/SmartlabProjects.php /elabftw/src/Models/SmartlabProjects.php
COPY apps/elabftw/src/Models/SmartlabSamples.php /elabftw/src/Models/SmartlabSamples.php
COPY apps/elabftw/src/Models/SmartlabReviews.php /elabftw/src/Models/SmartlabReviews.php
COPY apps/elabftw/src/Models/SmartlabExperiments.php /elabftw/src/Models/SmartlabExperiments.php
COPY apps/elabftw/src/Models/SmartlabAssayCatalog.php /elabftw/src/Models/SmartlabAssayCatalog.php
COPY apps/elabftw/src/Models/SmartlabInventory.php /elabftw/src/Models/SmartlabInventory.php
COPY apps/elabftw/src/Models/SmartlabItems.php /elabftw/src/Models/SmartlabItems.php
COPY apps/elabftw/src/Models/SmartlabDashboard.php /elabftw/src/Models/SmartlabDashboard.php
COPY apps/elabftw/src/Models/SmartlabScheduler.php /elabftw/src/Models/SmartlabScheduler.php

COPY apps/elabftw/src/templates/head.html /elabftw/src/templates/head.html
COPY apps/elabftw/src/templates/footer.html /elabftw/src/templates/footer.html
COPY apps/elabftw/src/templates/dashboard.html /elabftw/src/templates/dashboard.html
COPY apps/elabftw/src/templates/projects.html /elabftw/src/templates/projects.html
COPY apps/elabftw/src/templates/samples.html /elabftw/src/templates/samples.html
COPY apps/elabftw/src/templates/reviews.html /elabftw/src/templates/reviews.html
COPY apps/elabftw/src/templates/view.html /elabftw/src/templates/view.html
COPY apps/elabftw/src/templates/show-item.html /elabftw/src/templates/show-item.html
COPY apps/elabftw/src/templates/show-item-table.html /elabftw/src/templates/show-item-table.html
COPY apps/elabftw/src/templates/inventory.html /elabftw/src/templates/inventory.html
COPY apps/elabftw/src/templates/scheduler.html /elabftw/src/templates/scheduler.html
COPY apps/elabftw/src/templates/assay-catalog.html /elabftw/src/templates/assay-catalog.html

COPY apps/elabftw/web/projects.php /elabftw/web/projects.php
COPY apps/elabftw/web/samples.php /elabftw/web/samples.php
COPY apps/elabftw/web/reviews.php /elabftw/web/reviews.php
COPY apps/elabftw/web/scheduler.php /elabftw/web/scheduler.php
COPY apps/elabftw/web/create-assay-pack.php /elabftw/web/create-assay-pack.php
COPY apps/elabftw/web/assay-catalog.php /elabftw/web/assay-catalog.php

RUN /usr/bin/php84 -d memory_limit=256M -d open_basedir='' /usr/local/bin/composer dump-autoload --optimize --classmap-authoritative --no-dev
