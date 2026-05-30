CREATE TABLE boldcaosimage (
    object_id TEXT PRIMARY KEY
  , image_url TEXT
  , thumbnail_url TEXT
  , batch INTEGER
  , file_name TEXT
  , processid TEXT
  , sampleid TEXT
  , taxon TEXT
  , meta TEXT
  , copyright_holder TEXT
  , copyright_year TEXT
  , copyright_license TEXT
  , copyright_institution TEXT
  , photographer TEXT
);

CREATE INDEX "processid_idx" ON boldcaosimage(processid ASC);

CREATE TABLE "query" (
    id TEXT PRIMARY KEY
);
