USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[FileCategories]    Script Date: 4/10/2026 2:59:01 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[FileCategories](
	[FileCategoryID] [int] IDENTITY(1,1) NOT NULL,
	[CategoryName] [nvarchar](100) NOT NULL,
	[Description] [nvarchar](255) NULL,
	[IsActive] [bit] NOT NULL,
	[SortOrder] [int] NULL,
	[CreatedAt] [datetime] NULL,
 CONSTRAINT [PK_FileCategories] PRIMARY KEY CLUSTERED 
(
	[FileCategoryID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO

ALTER TABLE [dbo].[FileCategories] ADD  CONSTRAINT [DF_FileCategories_IsActive]  DEFAULT ((1)) FOR [IsActive]
GO

ALTER TABLE [dbo].[FileCategories] ADD  CONSTRAINT [DF_FileCategories_CreatedAt]  DEFAULT (getdate()) FOR [CreatedAt]
GO


