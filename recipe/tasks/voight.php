<?php
namespace Deployer;

// Voight is hosted on the thekindkids.be domain (Statik.be sister-org infrastructure).
set('statik_voight_script_url', 'https://voight.thekindkids.be/scripts/voight.sh');

desc('Download and run the Voight versioning script in the release path');
task('statik:voight', function () {
    cd('{{release_path}}');
    // The Voight API expects POST with a JSON content-type — matches the
    // voight_versioning.sh bootstrap script used in other Statik.be projects.
    run('curl -fsS -X POST -H "Content-Type: application/json" "{{statik_voight_script_url}}" -o voight.sh');
    try {
        run('bash voight.sh');
    } finally {
        run('rm -f voight.sh');
    }
});
