import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createRequire } from 'node:module';

const projectRoot = resolve(new URL('..', import.meta.url).pathname);
const dataDir = await mkdtemp(join(tmpdir(), 'bkb-test-'));
const testFile = join(projectRoot, 'tests', 'run.php');
const moduleRoot = process.env.PHP_WASM_MODULES;

if (!moduleRoot) {
    throw new Error('PHP_WASM_MODULES muss auf ein node_modules-Verzeichnis mit @php-wasm/node zeigen.');
}

const require = createRequire(import.meta.url);
const { PHP } = require(join(moduleRoot, '@php-wasm/universal'));
const { loadNodeRuntime, useHostFilesystem } = require(join(moduleRoot, '@php-wasm/node'));

const php = new PHP(
    await loadNodeRuntime('8.3', {
        emscriptenOptions: { processId: process.pid },
    })
);
useHostFilesystem(php);
const result = await php.runStream({
    code: `<?php
        putenv('BKB_DATA_DIR=${dataDir.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}');
        require '${testFile.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}';
    `,
});

const stdout = await result.stdoutText;
const stderr = await result.stderrText;

if (stdout) process.stdout.write(stdout);
if (stderr) process.stderr.write(stderr);
process.exit(await result.exitCode);
