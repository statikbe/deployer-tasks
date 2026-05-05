<?php
namespace Deployer;

set('statik_voight_script_url', 'https://voight.thekindkids.be/scripts/voight.sh');

desc('Download and run the Voight versioning script in the release path');
task('statik:voight', function () {
    cd('{{release_path}}');
    run('curl -fsS -X POST -H "Content-Type: application/json" {{statik_voight_script_url}} -o voight.sh');
    run('chmod +x voight.sh');
    run('bash voight.sh');
    run('rm -f voight.sh');
});
