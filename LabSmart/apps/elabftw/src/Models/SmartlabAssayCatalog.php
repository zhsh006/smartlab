<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabAssayCatalog extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function readSummary(): array
    {
        $req = $this->Db->prepare('SELECT
                (SELECT COUNT(*) FROM smartlab_primers WHERE team_id = :team AND is_active = 1) AS primer_count,
                (SELECT COUNT(*) FROM smartlab_antibodies WHERE team_id = :team AND is_active = 1) AS antibody_count,
                (SELECT COUNT(*) FROM smartlab_reagents WHERE team_id = :team AND is_active = 1) AS reagent_count,
                (SELECT COUNT(*) FROM smartlab_primers WHERE team_id = :team AND is_active = 0) AS archived_primer_count,
                (SELECT COUNT(*) FROM smartlab_antibodies WHERE team_id = :team AND is_active = 0) AS archived_antibody_count,
                (SELECT COUNT(*) FROM smartlab_reagents WHERE team_id = :team AND is_active = 0) AS archived_reagent_count');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetch();
    }

    public function readPrimerById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $req = $this->Db->prepare('SELECT
                id,
                code,
                target_gene,
                reference_gene,
                amplicon_bp,
                annealing_temperature,
                is_active,
                note
            FROM smartlab_primers
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : $row;
    }

    public function readPrimerUsage(int $id): array
    {
        if ($id <= 0) {
            return array();
        }
        $req = $this->Db->prepare('SELECT
                ap.experiment_id,
                ap.pack_key,
                ap.updated_at,
                e.title AS experiment_title
            FROM smartlab_assay_packs AS ap
            INNER JOIN experiments AS e ON e.id = ap.experiment_id
            WHERE ap.team_id = :team
              AND e.team = :team
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.primer.object.id")) AS UNSIGNED) = :id
            ORDER BY ap.updated_at DESC, ap.id DESC
            LIMIT 10');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAntibodyById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                application_scope,
                host_species,
                dilution_hint,
                target_name,
                is_active,
                note
            FROM smartlab_antibodies
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : $row;
    }

    public function readAntibodyUsage(int $id): array
    {
        if ($id <= 0) {
            return array();
        }
        $req = $this->Db->prepare('SELECT
                usage_rows.experiment_id,
                usage_rows.pack_key,
                usage_rows.object_slot,
                usage_rows.updated_at,
                e.title AS experiment_title
            FROM (
                SELECT experiment_id, pack_key, updated_at, "wb_primary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.primary_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "wb_secondary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.secondary_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "if_primary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.primary_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "if"
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "if_secondary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.secondary_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "if"
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "ihc_primary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.primary_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "ihc"
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "ihc_secondary" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.secondary_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "ihc"
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "elisa_capture" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.capture_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "elisa"
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "elisa_detection" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.detection_object.id")) AS UNSIGNED) = :id
                  AND pack_key = "elisa"
            ) AS usage_rows
            INNER JOIN experiments AS e ON e.id = usage_rows.experiment_id
            WHERE e.team = :team
            ORDER BY usage_rows.updated_at DESC
            LIMIT 10');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readReagentById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                reagent_scope,
                vendor,
                model_hint,
                is_active,
                note
            FROM smartlab_reagents
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : $row;
    }

    public function readReagentUsage(int $id): array
    {
        if ($id <= 0) {
            return array();
        }
        $req = $this->Db->prepare('SELECT
                usage_rows.experiment_id,
                usage_rows.pack_key,
                usage_rows.object_slot,
                usage_rows.updated_at,
                e.title AS experiment_title
            FROM (
                SELECT experiment_id, pack_key, updated_at, "qpcr_kit" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.setup.kit_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "qpcr_instrument" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.setup.instrument_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "wb_detection" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.detection_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "wb_membrane" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.membrane_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "if_blocking" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.blocking_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "if_microscope" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.imaging.microscope_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "ihc_fixative" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.sample.fixative_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "ihc_retrieval" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.antigen_retrieval_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "ihc_counterstain" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.staining.counterstain_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "elisa_kit" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.kit_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "elisa_substrate" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.reagent.substrate_object.id")) AS UNSIGNED) = :id
                UNION ALL
                SELECT experiment_id, pack_key, updated_at, "elisa_reader" AS object_slot
                FROM smartlab_assay_packs
                WHERE team_id = :team
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.run.reader_object.id")) AS UNSIGNED) = :id
            ) AS usage_rows
            INNER JOIN experiments AS e ON e.id = usage_rows.experiment_id
            WHERE e.team = :team
            ORDER BY usage_rows.updated_at DESC
            LIMIT 10');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readPrimers(string $search = ''): array
    {
        $searchSql = '';
        if ($search !== '') {
            $searchSql = ' AND (
                code LIKE :query
                OR target_gene LIKE :query
                OR reference_gene LIKE :query
                OR note LIKE :query
            )';
        }

        $req = $this->Db->prepare('SELECT
                id,
                code,
                target_gene,
                reference_gene,
                amplicon_bp,
                annealing_temperature,
                is_active,
                (
                    SELECT COUNT(*)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.primer.object.id")) AS UNSIGNED) = smartlab_primers.id
                ) AS usage_count,
                (
                    SELECT MAX(ap.updated_at)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.primer.object.id")) AS UNSIGNED) = smartlab_primers.id
                ) AS last_used_at,
                note,
                created_at
            FROM smartlab_primers
            WHERE team_id = :team' . $searchSql . '
            ORDER BY is_active DESC, code ASC, id DESC
            LIMIT 200');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($search !== '') {
            $req->bindValue(':query', '%' . $search . '%');
        }
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAntibodies(string $search = ''): array
    {
        $searchSql = '';
        if ($search !== '') {
            $searchSql = ' AND (
                code LIKE :query
                OR name LIKE :query
                OR application_scope LIKE :query
                OR target_name LIKE :query
                OR host_species LIKE :query
                OR note LIKE :query
            )';
        }

        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                application_scope,
                host_species,
                dilution_hint,
                target_name,
                is_active,
                (
                    SELECT COUNT(*)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND (
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.primary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.secondary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.primary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.secondary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                      )
                ) AS usage_count,
                (
                    SELECT MAX(ap.updated_at)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND (
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.primary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.secondary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.primary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.secondary_object.id")) AS UNSIGNED) = smartlab_antibodies.id
                      )
                ) AS last_used_at,
                note,
                created_at
            FROM smartlab_antibodies
            WHERE team_id = :team' . $searchSql . '
            ORDER BY is_active DESC, application_scope ASC, code ASC, id DESC
            LIMIT 200');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($search !== '') {
            $req->bindValue(':query', '%' . $search . '%');
        }
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readReagents(string $search = ''): array
    {
        $searchSql = '';
        if ($search !== '') {
            $searchSql = ' AND (
                code LIKE :query
                OR name LIKE :query
                OR reagent_scope LIKE :query
                OR vendor LIKE :query
                OR model_hint LIKE :query
                OR note LIKE :query
            )';
        }

        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                reagent_scope,
                vendor,
                model_hint,
                is_active,
                (
                    SELECT COUNT(*)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND (
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.setup.kit_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.setup.instrument_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.detection_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.membrane_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.blocking_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.imaging.microscope_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.sample.fixative_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.antigen_retrieval_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.counterstain_object.id")) AS UNSIGNED) = smartlab_reagents.id
                      )
                ) AS usage_count,
                (
                    SELECT MAX(ap.updated_at)
                    FROM smartlab_assay_packs AS ap
                    INNER JOIN experiments AS e ON e.id = ap.experiment_id
                    WHERE ap.team_id = :team
                      AND e.team = :team
                      AND (
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.setup.kit_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.setup.instrument_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.detection_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.reagent.membrane_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.blocking_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.imaging.microscope_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.sample.fixative_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.antigen_retrieval_object.id")) AS UNSIGNED) = smartlab_reagents.id
                        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.payload, "$.staining.counterstain_object.id")) AS UNSIGNED) = smartlab_reagents.id
                      )
                ) AS last_used_at,
                note,
                created_at
            FROM smartlab_reagents
            WHERE team_id = :team' . $searchSql . '
            ORDER BY is_active DESC, reagent_scope ASC, code ASC, id DESC
            LIMIT 200');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($search !== '') {
            $req->bindValue(':query', '%' . $search . '%');
        }
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function savePrimer(
        int $id,
        string $code,
        string $targetGene,
        ?string $referenceGene,
        ?string $ampliconBp,
        ?string $annealingTemperature,
        string $note,
    ): void {
        if ($code === '' || $targetGene === '') {
            return;
        }

        if ($id > 0) {
            $req = $this->Db->prepare('UPDATE smartlab_primers
                SET code = :code,
                    target_gene = :target_gene,
                    reference_gene = :reference_gene,
                    amplicon_bp = :amplicon_bp,
                    annealing_temperature = :annealing_temperature,
                    note = :note
                WHERE id = :id
                  AND team_id = :team_id');
            $req->bindValue(':id', $id, PDO::PARAM_INT);
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        } else {
            $req = $this->Db->prepare('INSERT INTO smartlab_primers
                    (team_id, code, target_gene, reference_gene, amplicon_bp, annealing_temperature, note, created_by)
                VALUES
                    (:team_id, :code, :target_gene, :reference_gene, :amplicon_bp, :annealing_temperature, :note, :created_by)');
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
            $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':code', $code);
        $req->bindValue(':target_gene', $targetGene);
        $this->bindNullableString($req, ':reference_gene', $referenceGene);
        $this->bindNullableString($req, ':amplicon_bp', $ampliconBp);
        $this->bindNullableString($req, ':annealing_temperature', $annealingTemperature);
        $req->bindValue(':note', $note);
        $this->Db->execute($req);
    }

    public function setPrimerActive(int $id, bool $isActive): void
    {
        $this->setActiveFlag('smartlab_primers', $id, $isActive);
    }

    public function saveAntibody(
        int $id,
        string $code,
        string $name,
        string $applicationScope,
        ?string $hostSpecies,
        ?string $dilutionHint,
        ?string $targetName,
        string $note,
    ): void {
        if ($code === '' || $name === '' || $applicationScope === '') {
            return;
        }

        if ($id > 0) {
            $req = $this->Db->prepare('UPDATE smartlab_antibodies
                SET code = :code,
                    name = :name,
                    application_scope = :application_scope,
                    host_species = :host_species,
                    dilution_hint = :dilution_hint,
                    target_name = :target_name,
                    note = :note
                WHERE id = :id
                  AND team_id = :team_id');
            $req->bindValue(':id', $id, PDO::PARAM_INT);
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        } else {
            $req = $this->Db->prepare('INSERT INTO smartlab_antibodies
                    (team_id, code, name, application_scope, host_species, dilution_hint, target_name, note, created_by)
                VALUES
                    (:team_id, :code, :name, :application_scope, :host_species, :dilution_hint, :target_name, :note, :created_by)');
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
            $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':application_scope', $applicationScope);
        $this->bindNullableString($req, ':host_species', $hostSpecies);
        $this->bindNullableString($req, ':dilution_hint', $dilutionHint);
        $this->bindNullableString($req, ':target_name', $targetName);
        $req->bindValue(':note', $note);
        $this->Db->execute($req);
        $this->syncAntibodyInventoryMaterial($code, $name, $applicationScope, $targetName, $note);
    }

    public function setAntibodyActive(int $id, bool $isActive): void
    {
        $this->setActiveFlag('smartlab_antibodies', $id, $isActive);
    }

    public function saveReagent(
        int $id,
        string $code,
        string $name,
        string $reagentScope,
        ?string $vendor,
        ?string $modelHint,
        string $note,
    ): void {
        if ($code === '' || $name === '' || $reagentScope === '') {
            return;
        }

        if ($id > 0) {
            $req = $this->Db->prepare('UPDATE smartlab_reagents
                SET code = :code,
                    name = :name,
                    reagent_scope = :reagent_scope,
                    vendor = :vendor,
                    model_hint = :model_hint,
                    note = :note
                WHERE id = :id
                  AND team_id = :team_id');
            $req->bindValue(':id', $id, PDO::PARAM_INT);
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        } else {
            $req = $this->Db->prepare('INSERT INTO smartlab_reagents
                    (team_id, code, name, reagent_scope, vendor, model_hint, note, created_by)
                VALUES
                    (:team_id, :code, :name, :reagent_scope, :vendor, :model_hint, :note, :created_by)');
            $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
            $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':reagent_scope', $reagentScope);
        $this->bindNullableString($req, ':vendor', $vendor);
        $this->bindNullableString($req, ':model_hint', $modelHint);
        $req->bindValue(':note', $note);
        $this->Db->execute($req);
        $this->syncReagentInventoryMaterial($code, $name, $reagentScope, $vendor, $modelHint, $note);
    }

    public function setReagentActive(int $id, bool $isActive): void
    {
        $this->setActiveFlag('smartlab_reagents', $id, $isActive);
    }

    private function bindNullableString(\PDOStatement $req, string $param, ?string $value): void
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $req->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $req->bindValue($param, $normalized);
    }

    private function setActiveFlag(string $tableName, int $id, bool $isActive): void
    {
        if ($id <= 0) {
            return;
        }
        $req = $this->Db->prepare(sprintf('UPDATE %s SET is_active = :is_active WHERE id = :id AND team_id = :team_id', $tableName));
        $req->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function syncAntibodyInventoryMaterial(string $code, string $name, string $applicationScope, ?string $targetName, string $note): void
    {
        $materialCode = 'MAT-' . $code;
        $specification = trim($applicationScope . ($targetName ? ' / ' . $targetName : ''));
        $req = $this->Db->prepare('INSERT INTO smartlab_materials
                (team_id, code, name, category, specification, unit, created_by)
            VALUES
                (:team_id, :code, :name, "antibody", :specification, "vial", :created_by)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                category = "antibody",
                specification = COALESCE(NULLIF(VALUES(specification), ""), specification),
                unit = COALESCE(unit, VALUES(unit))');
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $materialCode);
        $req->bindValue(':name', $name);
        $req->bindValue(':specification', $specification !== '' ? $specification : null);
        $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $materialId = (int) $this->Db->lastInsertId();
        if ($materialId <= 0) {
            $select = $this->Db->prepare('SELECT id FROM smartlab_materials WHERE team_id = :team_id AND code = :code LIMIT 1');
            $select->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
            $select->bindValue(':code', $materialCode);
            $this->Db->execute($select);
            $materialId = (int) $select->fetchColumn();
        }
        if ($materialId <= 0) {
            return;
        }

        $lotNote = trim($note) !== '' ? $note : '由抗体对象同步生成的测试库存批次。';
        $lot = $this->Db->prepare('INSERT INTO smartlab_material_lots
                (material_id, lot_no, quantity, location_label, note)
            VALUES
                (:material_id, :lot_no, 1, "-20C 抗体柜", :note)
            ON DUPLICATE KEY UPDATE
                quantity = GREATEST(quantity, VALUES(quantity)),
                note = COALESCE(NULLIF(VALUES(note), ""), note)');
        $lot->bindValue(':material_id', $materialId, PDO::PARAM_INT);
        $lot->bindValue(':lot_no', 'INIT-' . $code);
        $lot->bindValue(':note', $lotNote);
        $this->Db->execute($lot);
    }

    private function syncReagentInventoryMaterial(string $code, string $name, string $reagentScope, ?string $vendor, ?string $modelHint, string $note): void
    {
        $inventoryMeta = match ($reagentScope) {
            'western_blot_detection' => array('category' => 'WB detection', 'unit' => 'mL', 'location' => 'Cold room WB shelf'),
            'western_blot_membrane' => array('category' => 'WB membrane', 'unit' => 'cm2', 'location' => 'Membrane cabinet'),
            default => array(),
        };
        if ($inventoryMeta === array()) {
            return;
        }

        $materialCode = 'MAT-' . $code;
        $req = $this->Db->prepare('INSERT INTO smartlab_materials
                (team_id, code, name, category, brand, manufacturer, catalog_no, specification, unit, created_by)
            VALUES
                (:team_id, :code, :name, :category, :brand, :manufacturer, :catalog_no, :specification, :unit, :created_by)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                category = VALUES(category),
                brand = COALESCE(VALUES(brand), brand),
                manufacturer = COALESCE(VALUES(manufacturer), manufacturer),
                catalog_no = COALESCE(VALUES(catalog_no), catalog_no),
                specification = COALESCE(VALUES(specification), specification),
                unit = COALESCE(unit, VALUES(unit))');
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $materialCode);
        $req->bindValue(':name', $name);
        $req->bindValue(':category', $inventoryMeta['category']);
        $this->bindNullableString($req, ':brand', $vendor);
        $this->bindNullableString($req, ':manufacturer', $vendor);
        $this->bindNullableString($req, ':catalog_no', $modelHint);
        $req->bindValue(':specification', $reagentScope === 'western_blot_membrane' ? 'membrane' : 'detection reagent');
        $req->bindValue(':unit', $inventoryMeta['unit']);
        $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $materialId = (int) $this->Db->lastInsertId();
        if ($materialId <= 0) {
            $select = $this->Db->prepare('SELECT id FROM smartlab_materials WHERE team_id = :team_id AND code = :code LIMIT 1');
            $select->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
            $select->bindValue(':code', $materialCode);
            $this->Db->execute($select);
            $materialId = (int) $select->fetchColumn();
        }
        if ($materialId <= 0) {
            return;
        }

        $initialQuantity = $reagentScope === 'western_blot_membrane' ? 1000 : 50;
        $lotNote = trim($note) !== '' ? $note : '由 WB 对象同步生成的测试库存批次。';
        $lot = $this->Db->prepare('INSERT INTO smartlab_material_lots
                (material_id, lot_no, quantity, location_label, note)
            VALUES
                (:material_id, :lot_no, :quantity, :location_label, :note)
            ON DUPLICATE KEY UPDATE
                quantity = GREATEST(quantity, VALUES(quantity)),
                location_label = COALESCE(VALUES(location_label), location_label),
                note = COALESCE(NULLIF(VALUES(note), ""), note)');
        $lot->bindValue(':material_id', $materialId, PDO::PARAM_INT);
        $lot->bindValue(':lot_no', 'INIT-' . $code);
        $lot->bindValue(':quantity', $initialQuantity);
        $lot->bindValue(':location_label', $inventoryMeta['location']);
        $lot->bindValue(':note', $lotNote);
        $this->Db->execute($lot);
    }
}
