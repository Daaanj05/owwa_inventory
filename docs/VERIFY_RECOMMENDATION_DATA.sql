-- =============================================================================
-- Run these in MySQL Workbench (database: Capstone_DB or your DB name)
-- to verify the same data that feeds the AI recommendation.
-- =============================================================================

-- 1) Items with reorder level (only these are sent to the AI)
-- -----------------------------------------------------------------------------
SELECT id, name, reorder_level, unit
FROM items
WHERE reorder_level > 0
ORDER BY name;


-- 2) Current stock per item per office (same formula as the app)
--    Stock = acquisitions + transfers_in - issuances - transfers_out - disposals
-- -----------------------------------------------------------------------------
SELECT
    i.id          AS item_id,
    i.name        AS item_name,
    i.reorder_level,
    o.id          AS office_id,
    o.name        AS office_name,
    (COALESCE(a.qty, 0) + COALESCE(t_in.qty, 0) - COALESCE(iss.qty, 0) - COALESCE(t_out.qty, 0) - COALESCE(d.qty, 0)) AS stock
FROM items i
CROSS JOIN offices o
LEFT JOIN (
    SELECT item_id, office_id, SUM(quantity) AS qty
    FROM acquisitions
    GROUP BY item_id, office_id
) a ON a.item_id = i.id AND a.office_id = o.id
LEFT JOIN (
    SELECT item_id, to_office_id AS office_id, SUM(quantity) AS qty
    FROM transfers
    GROUP BY item_id, to_office_id
) t_in ON t_in.item_id = i.id AND t_in.office_id = o.id
LEFT JOIN (
    SELECT item_id, office_id, SUM(quantity) AS qty
    FROM issuances
    GROUP BY item_id, office_id
) iss ON iss.item_id = i.id AND iss.office_id = o.id
LEFT JOIN (
    SELECT item_id, from_office_id AS office_id, SUM(quantity) AS qty
    FROM transfers
    GROUP BY item_id, from_office_id
) t_out ON t_out.item_id = i.id AND t_out.office_id = o.id
LEFT JOIN (
    SELECT item_id, office_id, SUM(quantity) AS qty
    FROM disposals
    GROUP BY item_id, office_id
) d ON d.item_id = i.id AND d.office_id = o.id
WHERE i.reorder_level > 0
ORDER BY i.name, o.name;


-- 3) Inventory usage (last 6 months) – issuance totals by item and office
--    This is the “consumption” data sent to the AI.
-- -----------------------------------------------------------------------------
SELECT
    i.name        AS item_name,
    o.name        AS office_name,
    SUM(iss.quantity) AS total_issued
FROM issuances iss
JOIN items i   ON i.id = iss.item_id
JOIN offices o ON o.id = iss.office_id
WHERE iss.issuance_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY iss.item_id, iss.office_id, i.name, o.name
ORDER BY i.name, o.name;


-- 4) Quick check: one row per item with reorder_level > 0 and its stock at first office
--    (Matches the “Current stock and reorder levels” section the AI sees.)
-- -----------------------------------------------------------------------------
SELECT
    i.name           AS item_name,
    i.reorder_level,
    o.name           AS office_name,
    (SELECT COALESCE(SUM(quantity), 0) FROM acquisitions WHERE item_id = i.id AND office_id = o.id)
  - (SELECT COALESCE(SUM(quantity), 0) FROM issuances   WHERE item_id = i.id AND office_id = o.id)
  + (SELECT COALESCE(SUM(quantity), 0) FROM transfers   WHERE item_id = i.id AND to_office_id = o.id)
  - (SELECT COALESCE(SUM(quantity), 0) FROM transfers   WHERE item_id = i.id AND from_office_id = o.id)
  - (SELECT COALESCE(SUM(quantity), 0) FROM disposals   WHERE item_id = i.id AND office_id = o.id)
    AS stock
FROM items i
CROSS JOIN offices o
WHERE i.reorder_level > 0
ORDER BY i.name, o.name;
