USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[ApplicationFiles]    Script Date: 4/10/2026 2:55:49 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[ApplicationFiles](
	[FileID] [int] IDENTITY(1,1) NOT NULL,
	[ApplicationID] [int] NOT NULL,
	[FileName] [nvarchar](255) NOT NULL,
	[FilePath] [nvarchar](500) NOT NULL,
	[FileType] [nvarchar](100) NULL,
	[UploadedAt] [datetime] NULL,
	[Remarks] [nvarchar](max) NULL,
	[FileCategoryID] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[FileID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO

ALTER TABLE [dbo].[ApplicationFiles] ADD  DEFAULT (getdate()) FOR [UploadedAt]
GO

ALTER TABLE [dbo].[ApplicationFiles]  WITH CHECK ADD  CONSTRAINT [FK_ApplicationFiles_FileCategories] FOREIGN KEY([FileCategoryID])
REFERENCES [dbo].[FileCategories] ([FileCategoryID])
GO

ALTER TABLE [dbo].[ApplicationFiles] CHECK CONSTRAINT [FK_ApplicationFiles_FileCategories]
GO


