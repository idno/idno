-- migrate_annotations()
-- move to /warmup/schemas/{date}.sql when ready
DELIMITER //

CREATE PROCEDURE migrate_annotations()
BEGIN
    DECLARE row_iterator INT;
    SET row_iterator = 0;
    SELECT 
      @total_entities_count := COUNT(*)
      FROM `entities`
      WHERE JSON_CONTAINS_PATH(
          JSON_UNQUOTE(`contents`),
          'all',
          "$.annotations"
      );
    WHILE row_iterator < @total_entities_count DO
        SELECT 
          @_id := _id,
          @uuid := uuid,
          @siteid := siteid,
          @siteurl := SUBSTRING_INDEX(uuid, 'view', 1) as 'siteurl'
          FROM `entities` 
          WHERE JSON_CONTAINS_PATH(
                  JSON_UNQUOTE(`contents`), 
                  'all', 
                  "$.annotations"
                )
          LIMIT row_iterator,1
          ;

        SELECT @total_types_count := COUNT(
            JSON_KEYS(
              JSON_UNQUOTE(`contents`), 
              "$.annotations"
            )
          )
          FROM `entities` 
          WHERE `_id` = @_id
        ;
        
        SET @annotation_type_iterator = 0;
        WHILE @annotation_type_iterator < @total_types_count DO

          SELECT @annotation_type :=
            JSON_EXTRACT(
              JSON_KEYS(
                JSON_UNQUOTE(`contents`), 
                "$.annotations"
              ),
              CONCAT('$[', JSON_UNQUOTE(@annotation_type_iterator), ']')  -- gets type from array position
            )
            FROM `entities` 
            WHERE `_id` = @_id
          ;
          SELECT @annotations_per_type_count :=
            JSON_LENGTH(
              JSON_KEYS(
                JSON_UNQUOTE(`contents`), 
                CONCAT('$.annotations.', @annotation_type, '')
              )
            )
            FROM `entities` 
            WHERE `_id` = @_id
          ;
          
          SET @annotations_iterator = 0;
          WHILE @annotations_iterator < @annotations_per_type_count DO

            SELECT @annotation_key_id :=
              JSON_EXTRACT(
                JSON_KEYS(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type)
                ),
                CONCAT('$[', JSON_UNQUOTE(@annotations_iterator), ']')
              )
              FROM `entities` 
              WHERE `_id` = @_id
            ;
            
            SELECT 
              @annotation_id :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.permalink')
                ),
              @owner_url :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`),
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.owner_url')
                ),
              @owner_name :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.owner_name')
                ),
              @owner_image :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.owner_image')
                ),
              @annotation_content :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.content')
                ),
              @annotation_time :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.time')
                ),
              @annotation_title :=
                JSON_EXTRACT(
                  JSON_UNQUOTE(`contents`), 
                  CONCAT('$.annotations.', @annotation_type, '.', @annotation_key_id, '.title')
                )
              FROM `entities` 
              WHERE `_id` = @_id
            ;

            if exists (
              SELECT 
                 *
              FROM `entities`
              WHERE 
                  `entity_subtype` = 'Idno\\Entities\\User'
                AND
                  `siteid` <=> JSON_UNQUOTE(@siteid)
                AND 
                 JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(`contents`), "$.handle")) = JSON_UNQUOTE(SUBSTRING_INDEX(JSON_UNQUOTE(@owner_url), 'profile/', -1))
            ) then
                SELECT 
                    @annotation_author := uuid
                  FROM `entities`
                  WHERE 
                    `entity_subtype` = 'Idno\\Entities\\User'
                    AND
                    `siteid` <=> JSON_UNQUOTE(@siteid)
                    AND 
                    JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(`contents`), "$.handle")) = JSON_UNQUOTE(SUBSTRING_INDEX(JSON_UNQUOTE(@owner_url), 'profile/', -1))
                  LIMIT 1
                ;
            elseif exists (
              SELECT 
                 *
              FROM `entities`
              WHERE 
                  `entity_subtype` = 'Idno\\Entities\\User'
                AND
                  `siteid` <=> JSON_UNQUOTE(@siteid)
                AND 
                 JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(`contents`), "$.owner_url")) = JSON_UNQUOTE(@owner_url)
            ) then
                SELECT 
                    @annotation_author := uuid
                  FROM `entities`
                  WHERE 
                    `entity_subtype` = 'Idno\\Entities\\User'
                    AND
                    `siteid` <=> JSON_UNQUOTE(@siteid)
                    AND 
                    JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(`contents`), "$.owner_url")) = JSON_UNQUOTE(@owner_url)
                  LIMIT 1
                ;
            else 
              INSERT INTO `entities` (uuid, _id, siteid, owner, entity_subtype, contents)
                SELECT 
                  @annotation_author := CONCAT(JSON_UNQUOTE(@siteurl),'view/',UUID()) AS uuid,
                  UUID() AS _id, 
                  JSON_UNQUOTE(@siteid) AS siteid,
                  '' AS owner,
                  JSON_UNQUOTE('Idno\\Entities\\User') AS entity_subtype,
                  JSON_OBJECT(
                    'owner_name', JSON_UNQUOTE(@owner_name),
                    'owner_url', JSON_UNQUOTE(@owner_url),
                    'owner_icon', JSON_UNQUOTE(@owner_image)
                  ) AS contents
                ;
            end if;

            INSERT INTO `annotations` (uuid, _id, siteid, owner, entity_subtype, created, contents, entity_id)
            VALUES(
              JSON_UNQUOTE(@annotation_key_id),
              SUBSTR(JSON_UNQUOTE(@annotation_key_id), -32),
              JSON_UNQUOTE(@siteid),
              @annotation_author,
              CONCAT('Idno\\Annotations\\',UCASE(LEFT(JSON_UNQUOTE(@annotation_type), 1)),SUBSTRING(JSON_UNQUOTE(@annotation_type), 2)),
              FROM_UNIXTIME(@annotation_time),
              JSON_OBJECT(
                'permalink', JSON_UNQUOTE(@annotation_id),
                'content', JSON_UNQUOTE(@annotation_content),
                'time', JSON_UNQUOTE(@annotation_time),
                'title', JSON_UNQUOTE(@annotation_title),
                'owner', JSON_UNQUOTE(@annotation_author)
              ),
              JSON_UNQUOTE(@uuid)
            );

            SET @annotations_iterator = @annotations_iterator + 1;
          END WHILE;
          SET @annotation_type_iterator = @annotation_type_iterator + 1;
        END WHILE;
        -- UPDATE `entities`
        --   SET `contents` = JSON_REMOVE(
        --       JSON_UNQUOTE(`contents`), 
        --       "$.annotations"
        --     )
        --   WHERE `_id` = @_id;
        SET row_iterator = row_iterator + 1;
    END WHILE;
END //

DELIMITER ;
