-- Manageable Announcement Category lookup (admin can add/edit later)
CREATE TABLE TBL_Bulletin_Category (
    CategoryID   INT IDENTITY(1,1) PRIMARY KEY,
    CategoryName VARCHAR(100) NOT NULL,
    IsActive     BIT NOT NULL DEFAULT 1
);

INSERT INTO TBL_Bulletin_Category (CategoryName) VALUES
('New Product Added'),
('Memorandum'),
('Operational Schedule'),
('Office Announcement'),
('IT Support / Technical Visit'),
('Audit Team Visit'),
('General Announcement');

ALTER TABLE TBL_Bulletin ADD CategoryID INT NULL
    REFERENCES TBL_Bulletin_Category(CategoryID);
-- Existing bulletins keep CategoryID = NULL; the app displays those as "General Announcement".

-- Targeting: NO ROWS for a BulletinID in either table = visible to everyone for that dimension.
-- This is how "show to all" is represented — no explicit flag column needed.
CREATE TABLE TBL_Bulletin_TargetDepartment (
    ID         INT IDENTITY(1,1) PRIMARY KEY,
    BulletinID INT NOT NULL REFERENCES TBL_Bulletin(BulletinID) ON DELETE CASCADE,
    Department VARCHAR(100) NOT NULL
);

CREATE TABLE TBL_Bulletin_TargetBranch (
    ID         INT IDENTITY(1,1) PRIMARY KEY,
    BulletinID INT NOT NULL REFERENCES TBL_Bulletin(BulletinID) ON DELETE CASCADE,
    Branch     VARCHAR(100) NOT NULL
);