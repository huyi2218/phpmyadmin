<?php
/**
 * Contains PhpMyAdmin\Plugins\Schema\Svg\RelationStatsSvg class
 */

declare(strict_types=1);

namespace PhpMyAdmin\Plugins\Schema\Svg;

use PhpMyAdmin\Plugins\Schema\Dia\TableStatsDia;
use PhpMyAdmin\Plugins\Schema\Eps\TableStatsEps;
use PhpMyAdmin\Plugins\Schema\ExportRelationSchema;
use PhpMyAdmin\Plugins\Schema\Pdf\TableStatsPdf;

use function in_array;
use function max;
use function min;
use function sprintf;

/**
 * RelationStatsSvg Relation Schema Class
 *
 * Purpose of this class is to generate the SVG XML Document because
 * SVG defines the graphics in XML format which is used for representing
 * the database diagrams as vector image. This class actually helps
 *  in preparing SVG XML format.
 *
 * SVG XML is generated by using XMLWriter php extension and this class
 * inherits ExportRelationSchema class has common functionality added
 * to this class
 *
 * @name Svg_Relation_Schema
 */
class SvgRelationSchema extends ExportRelationSchema
{
    /** @var TableStatsDia[]|TableStatsEps[]|TableStatsPdf[]|TableStatsSvg[] */
    private $tables = [];

    /** @var RelationStatsSvg[] Relations */
    private $relations = [];

    /** @var int|float */
    private $xMax = 0;

    /** @var int|float */
    private $yMax = 0;

    /** @var int|float */
    private $xMin = 100000;

    /** @var int|float */
    private $yMin = 100000;

    /** @var int */
    private $tablewidth;

    /**
     * Upon instantiation This starts writing the SVG XML document
     * user will be prompted for download as .svg extension
     *
     * @see PMA_SVG
     *
     * @param string $db database name
     */
    public function __construct($db)
    {
        parent::__construct($db, new Svg());

        $this->setShowColor(isset($_REQUEST['svg_show_color']));
        $this->setShowKeys(isset($_REQUEST['svg_show_keys']));
        $this->setTableDimension(isset($_REQUEST['svg_show_table_dimension']));
        $this->setAllTablesSameWidth(isset($_REQUEST['svg_all_tables_same_width']));

        $this->diagram->setTitle(
            sprintf(
                __('Schema of the %s database - Page %s'),
                $this->db,
                $this->pageNumber
            )
        );
        $this->diagram->SetAuthor('phpMyAdmin ' . PMA_VERSION);
        $this->diagram->setFont('Arial');
        $this->diagram->setFontSize(16);

        $alltables = $this->getTablesFromRequest();

        foreach ($alltables as $table) {
            if (! isset($this->tables[$table])) {
                $this->tables[$table] = new TableStatsSvg(
                    $this->diagram,
                    $this->db,
                    $table,
                    $this->diagram->getFont(),
                    $this->diagram->getFontSize(),
                    $this->pageNumber,
                    $this->tablewidth,
                    $this->showKeys,
                    $this->tableDimension,
                    $this->offline
                );
            }

            if ($this->sameWide) {
                $this->tables[$table]->width = &$this->tablewidth;
            }
            $this->setMinMax($this->tables[$table]);
        }

        $border = 15;
        $this->diagram->startSvgDoc(
            $this->xMax + $border,
            $this->yMax + $border,
            $this->xMin - $border,
            $this->yMin - $border
        );

        $seen_a_relation = false;
        foreach ($alltables as $one_table) {
            $exist_rel = $this->relation->getForeigners($this->db, $one_table, '', 'both');
            if (! $exist_rel) {
                continue;
            }

            $seen_a_relation = true;
            foreach ($exist_rel as $master_field => $rel) {
                /* put the foreign table on the schema only if selected
                * by the user
                * (do not use array_search() because we would have to
                * to do a === false and this is not PHP3 compatible)
                */
                if ($master_field !== 'foreign_keys_data') {
                    if (in_array($rel['foreign_table'], $alltables)) {
                        $this->addRelation(
                            $one_table,
                            $this->diagram->getFont(),
                            $this->diagram->getFontSize(),
                            $master_field,
                            $rel['foreign_table'],
                            $rel['foreign_field'],
                            $this->tableDimension
                        );
                    }
                    continue;
                }

                foreach ($rel as $one_key) {
                    if (! in_array($one_key['ref_table_name'], $alltables)) {
                        continue;
                    }

                    foreach ($one_key['index_list'] as $index => $one_field) {
                        $this->addRelation(
                            $one_table,
                            $this->diagram->getFont(),
                            $this->diagram->getFontSize(),
                            $one_field,
                            $one_key['ref_table_name'],
                            $one_key['ref_index_list'][$index],
                            $this->tableDimension
                        );
                    }
                }
            }
        }
        if ($seen_a_relation) {
            $this->drawRelations();
        }

        $this->drawTables();
        $this->diagram->endSvgDoc();
    }

    /**
     * Output RelationStatsSvg Document for download
     *
     * @return void
     */
    public function showOutput()
    {
        $this->diagram->showOutput($this->getFileName('.svg'));
    }

    /**
     * Sets X and Y minimum and maximum for a table cell
     *
     * @param TableStatsSvg $table The table
     *
     * @return void
     */
    private function setMinMax($table)
    {
        $this->xMax = max($this->xMax, $table->x + $table->width);
        $this->yMax = max($this->yMax, $table->y + $table->height);
        $this->xMin = min($this->xMin, $table->x);
        $this->yMin = min($this->yMin, $table->y);
    }

    /**
     * Defines relation objects
     *
     * @see setMinMax,TableStatsSvg::__construct(),
     *       PhpMyAdmin\Plugins\Schema\Svg\RelationStatsSvg::__construct()
     *
     * @param string $masterTable    The master table name
     * @param string $font           The font face
     * @param int    $fontSize       Font size
     * @param string $masterField    The relation field in the master table
     * @param string $foreignTable   The foreign table name
     * @param string $foreignField   The relation field in the foreign table
     * @param bool   $tableDimension Whether to display table position or not
     *
     * @return void
     */
    private function addRelation(
        $masterTable,
        $font,
        $fontSize,
        $masterField,
        $foreignTable,
        $foreignField,
        $tableDimension
    ) {
        if (! isset($this->tables[$masterTable])) {
            $this->tables[$masterTable] = new TableStatsSvg(
                $this->diagram,
                $this->db,
                $masterTable,
                $font,
                $fontSize,
                $this->pageNumber,
                $this->tablewidth,
                false,
                $tableDimension
            );
            $this->setMinMax($this->tables[$masterTable]);
        }
        if (! isset($this->tables[$foreignTable])) {
            $this->tables[$foreignTable] = new TableStatsSvg(
                $this->diagram,
                $this->db,
                $foreignTable,
                $font,
                $fontSize,
                $this->pageNumber,
                $this->tablewidth,
                false,
                $tableDimension
            );
            $this->setMinMax($this->tables[$foreignTable]);
        }
        $this->relations[] = new RelationStatsSvg(
            $this->diagram,
            $this->tables[$masterTable],
            $masterField,
            $this->tables[$foreignTable],
            $foreignField
        );
    }

    /**
     * Draws relation arrows and lines
     * connects master table's master field to
     * foreign table's foreign field
     *
     * @see Relation_Stats_Svg::relationDraw()
     *
     * @return void
     */
    private function drawRelations()
    {
        foreach ($this->relations as $relation) {
            $relation->relationDraw($this->showColor);
        }
    }

    /**
     * Draws tables
     *
     * @see TableStatsSvg::Table_Stats_tableDraw()
     *
     * @return void
     */
    private function drawTables()
    {
        foreach ($this->tables as $table) {
            $table->tableDraw($this->showColor);
        }
    }
}
