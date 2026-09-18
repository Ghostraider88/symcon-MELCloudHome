<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

/**
 * Basis-Validierung der Bibliothek.
 *
 * Nutzt das SymconStubs-Submodul (siehe tests/README.md) und prüft, ob library.json
 * und alle module.json den Anforderungen entsprechen. Sehr empfohlen laut Symcon
 * Best Practices.
 */
class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateConnectionModule(): void
    {
        $this->validateModule(__DIR__ . '/../MELCloudConnection');
    }

    public function testValidateConfiguratorModule(): void
    {
        $this->validateModule(__DIR__ . '/../MELCloudConfigurator');
    }

    public function testValidateDeviceModule(): void
    {
        $this->validateModule(__DIR__ . '/../MELCloudKlimageraet');
    }
}
